<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

declare(strict_types=1);

namespace ILIAS\LearningSequence\Player;

use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover;
use ILIAS\LearningSequence\Content\Condition\ConditionFactory;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\InputCondition\LearningProgressInputConditions\LearningProgressInputAwareCondition;

/**
 * Adaptive navigation: successors and predecessors are not derived from the
 * fixed list order but from the input-/output-conditions of the objects.
 *
 * The graph edges are encoded by the LearningProgressInputAwareCondition: an object X
 * carrying a LearningProgressInputAwareCondition with target_ref_id = Y expresses the
 * edge Y -> X (i.e. X is a successor of Y and Y is a predecessor of X).
 *
 * The conditions are re-evaluated on every request: an object may only be left
 * if all of its output-conditions are fulfilled, and it may only be entered if
 * all of its input-conditions are fulfilled.
 */
class AdaptiveNavigator implements LSNavigator
{
    protected ilObjLearningSequenceConditionDiscover $discoverer;
    protected ConditionFactory $condition_factory;

    /**
     * Request-caches. The conditions of an object and their evaluation do not
     * change while a single request is being processed, but they are asked for
     * over and over again while the graph is traversed.
     *
     * @var array<int, AbstractCondition[]>
     */
    protected array $conditions_cache = [];

    /**
     * @var array<int, int[]> the graph predecessors per ref_id
     */
    protected array $edge_targets_cache = [];

    /**
     * @var array<int, bool>
     */
    protected array $can_leave_cache = [];

    /**
     * @var array<int, bool>
     */
    protected array $can_enter_ignoring_edges_cache = [];

    public function __construct(
        ?ilObjLearningSequenceConditionDiscover $discoverer = null,
        ?ConditionFactory $condition_factory = null
    ) {
        global $DIC;
        $this->discoverer = $discoverer ?? new ilObjLearningSequenceConditionDiscover();
        $this->condition_factory = $condition_factory
            ?? new ConditionFactory($this->discoverer, $DIC->database());
    }

    /**
     * Loads the condition-ids of all given items with one single query instead
     * of one query per item and fills the condition cache. Calling this before
     * traversing the graph turns the repeated lookups of the traversal into
     * pure array lookups.
     *
     * @param \LSLearnerItem[] $items
     */
    public function preload(array $items): void
    {
        $ref_ids = [];
        foreach ($items as $item) {
            $ref_ids[] = $item->getRefId();
        }
        $ref_ids = array_values(array_filter(
            array_unique($ref_ids),
            fn(int $ref_id): bool => !isset($this->conditions_cache[$ref_id])
        ));

        if ($ref_ids === []) {
            return;
        }

        $ids_per_item = $this->discoverer->preloadConditionIdsForItems($ref_ids);

        foreach ($ids_per_item as $ref_id => $ids) {
            $conditions = [];
            foreach ($ids as $id) {
                try {
                    $conditions[] = $this->condition_factory->getConditionInstanceById($id);
                } catch (\Throwable $t) {
                    continue;
                }
            }
            $this->conditions_cache[$ref_id] = $conditions;
        }
    }

    /**
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getSuccessors(array $items, \LSLearnerItem $current): array
    {
        $successors = [];
        foreach ($items as $item) {
            if ($item->getRefId() === $current->getRefId()) {
                continue;
            }
            if (!$this->isEdge($current->getRefId(), $item->getRefId())) {
                continue;
            }
            if (!$this->canEnterFrom($current, $item)) {
                continue;
            }
            $successors[] = $item;
        }
        return $successors;
    }

    /**
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getStructuralSuccessors(array $items, \LSLearnerItem $current): array
    {
        $successors = [];
        foreach ($items as $item) {
            if ($item->getRefId() === $current->getRefId()) {
                continue;
            }
            if (!$this->isEdge($current->getRefId(), $item->getRefId())) {
                continue;
            }
            $successors[] = $item;
        }
        return $successors;
    }

    /**
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getPredecessors(array $items, \LSLearnerItem $current): array
    {
        $predecessor_ref_ids = $this->getEdgeTargetsFor($current->getRefId());
        $predecessors = [];
        foreach ($items as $item) {
            if (in_array($item->getRefId(), $predecessor_ref_ids, true)) {
                $predecessors[] = $item;
            }
        }
        return $predecessors;
    }

    public function canLeave(\LSLearnerItem $current): bool
    {
        $ref_id = $current->getRefId();
        if (isset($this->can_leave_cache[$ref_id])) {
            return $this->can_leave_cache[$ref_id];
        }

        $can_leave = true;
        foreach ($this->getConditionsFor($ref_id) as $condition) {
            if ($condition instanceof OutputConditionInterface && !$this->checkCondition($condition)) {
                $can_leave = false;
                break;
            }
        }

        $this->can_leave_cache[$ref_id] = $can_leave;
        return $can_leave;
    }

    public function canEnter(\LSLearnerItem $target): bool
    {
        foreach ($this->getConditionsFor($target->getRefId()) as $condition) {
            if ($condition instanceof InputConditionInterface && !$this->checkCondition($condition)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks only the input-conditions that do NOT encode a graph edge. The
     * edge conditions (LearningProgressInputAwareCondition) must not be AND-combined,
     * because several of them describe ALTERNATIVE incoming paths; whether one
     * of those paths is open is decided via the predecessors (canLeave).
     */
    public function canEnterIgnoringEdges(\LSLearnerItem $target): bool
    {
        $ref_id = $target->getRefId();
        if (isset($this->can_enter_ignoring_edges_cache[$ref_id])) {
            return $this->can_enter_ignoring_edges_cache[$ref_id];
        }

        $can_enter = true;
        foreach ($this->getConditionsFor($ref_id) as $condition) {
            if ($condition instanceof LearningProgressInputAwareCondition) {
                continue;
            }
            if ($condition instanceof InputConditionInterface && !$this->checkCondition($condition)) {
                $can_enter = false;
                break;
            }
        }

        $this->can_enter_ignoring_edges_cache[$ref_id] = $can_enter;
        return $can_enter;
    }

    /**
     * Whether the target object may be entered coming from $current.
     *
     * Only the edge condition of the edge actually used is evaluated; all other
     * LearningProgressInputConditions of the target describe ALTERNATIVE incoming
     * paths and must not be AND-combined - otherwise an object with several
     * incoming edges (e.g. the goal reachable via P1 or P2) would never be
     * enterable and a branch would silently collapse into a single successor.
     */
    public function canEnterFrom(\LSLearnerItem $current, \LSLearnerItem $target): bool
    {
        foreach ($this->getConditionsFor($target->getRefId()) as $condition) {
            if ($condition instanceof LearningProgressInputAwareCondition) {
                $is_current_edge = false;
                try {
                    $is_current_edge = $condition->getConditionTargetRefId() === $current->getRefId();
                } catch (\Throwable $t) {
                    continue;
                }
                if ($is_current_edge && !$this->checkCondition($condition)) {
                    return false;
                }
                continue;
            }
            if ($condition instanceof InputConditionInterface && !$this->checkCondition($condition)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The condition-ids of ALL input-conditions of the given object (not only
     * the LearningProgressInputAwareCondition that forms the graph edges). Used by the
     * map data layer to expose which conditions must be met to enter an object.
     *
     * @return int[]
     */
    public function getInputConditionIds(\LSLearnerItem $item): array
    {
        $ids = [];
        foreach ($this->getConditionsFor($item->getRefId()) as $condition) {
            if ($condition instanceof InputConditionInterface) {
                $id = $condition->getConditionId();
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * The condition-ids of ALL output-conditions of the given object. Used by
     * the map data layer to expose which conditions must be met to leave an
     * object.
     *
     * @return int[]
     */
    public function getOutputConditionIds(\LSLearnerItem $item): array
    {
        $ids = [];
        foreach ($this->getConditionsFor($item->getRefId()) as $condition) {
            if ($condition instanceof OutputConditionInterface) {
                $id = $condition->getConditionId();
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * Evaluates a single condition defensively. Some condition checks rely on
     * the learning-progress subsystem (e.g. LearningProgressInputAwareCondition ->
     * ilLPStatus::_hasUserCompleted), which can throw when the referenced
     * object has no valid LP mode configured (LP_MODE_UNDEFINED). In that case
     * the condition is treated as NOT fulfilled instead of letting the
     * exception bubble up and crash the whole player.
     */
    protected function checkCondition(AbstractCondition $condition): bool
    {
        try {
            return $condition->check();
        } catch (\Throwable $t) {
            return false;
        }
    }

    /**
     * Whether there is a graph edge $from_ref_id -> $to_ref_id, i.e. the target
     * object carries a LearningProgressInputAwareCondition pointing back to the source.
     */
    protected function isEdge(int $from_ref_id, int $to_ref_id): bool
    {
        return in_array($from_ref_id, $this->getEdgeTargetsFor($to_ref_id), true);
    }

    /**
     * Returns the ref_ids the given object points to via its
     * LearningProgressInputConditions (its graph predecessors).
     *
     * @return int[]
     */
    protected function getEdgeTargetsFor(int $item_ref_id): array
    {
        if (isset($this->edge_targets_cache[$item_ref_id])) {
            return $this->edge_targets_cache[$item_ref_id];
        }

        $targets = [];
        foreach ($this->getConditionsFor($item_ref_id) as $condition) {
            if ($condition instanceof LearningProgressInputAwareCondition) {
                try {
                    $targets[] = $condition->getConditionTargetRefId();
                } catch (\Throwable $t) {
                    continue;
                }
            }
        }

        $this->edge_targets_cache[$item_ref_id] = $targets;
        return $targets;
    }

    /**
     * @return \ILIAS\LearningSequence\Content\Condition\AbstractCondition[]
     */
    protected function getConditionsFor(int $item_ref_id): array
    {
        if (isset($this->conditions_cache[$item_ref_id])) {
            return $this->conditions_cache[$item_ref_id];
        }

        $conditions = [];
        foreach ($this->discoverer->getAllConditionIdsForItem($item_ref_id) as $condition_id) {
            try {
                $conditions[] = $this->condition_factory->getConditionInstanceById($condition_id);
            } catch (\Throwable $t) {
                continue;
            }
        }

        $this->conditions_cache[$item_ref_id] = $conditions;
        return $conditions;
    }
}
