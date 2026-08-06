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
use ILIAS\LearningSequence\Content\Condition\InputCondition\SingleChoiceInputCondition\SingleChoiceInputCondition;

/**
 * Adaptive navigation: successors and predecessors are not derived from the
 * fixed list order but from the input-/output-conditions of the objects.
 *
 * The graph edges are encoded by the SingleChoiceInputCondition: an object X
 * carrying a SingleChoiceInputCondition with target_ref_id = Y expresses the
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
            if (!$this->canEnter($item)) {
                continue;
            }
            $successors[] = $item;
        }
        return $successors;
    }

    /**
     * Returns the structural graph successors of the current object regardless
     * of whether the learner may already enter them. This is used for map
     * visualizations that need the full route including currently blocked
     * branches.
     *
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getGraphSuccessors(array $items, \LSLearnerItem $current): array
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
        foreach ($this->getConditionsFor($current->getRefId()) as $condition) {
            if ($condition instanceof OutputConditionInterface && !$this->checkCondition($condition)) {
                return false;
            }
        }
        return true;
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
     * The condition-ids of ALL input-conditions of the given object (not only
     * the SimpleChoiceInputCondition that forms the graph edges). Used by the
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
     * the learning-progress subsystem (e.g. SingleChoiceInputCondition ->
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
     * object carries a SingleChoiceInputCondition pointing back to the source.
     */
    protected function isEdge(int $from_ref_id, int $to_ref_id): bool
    {
        return in_array($from_ref_id, $this->getEdgeTargetsFor($to_ref_id), true);
    }

    /**
     * Returns the ref_ids the given object points to via its
     * SingleChoiceInputConditions (its graph predecessors).
     *
     * @return int[]
     */
    protected function getEdgeTargetsFor(int $item_ref_id): array
    {
        $targets = [];
        foreach ($this->getConditionsFor($item_ref_id) as $condition) {
            if ($condition instanceof SingleChoiceInputCondition) {
                try {
                    $targets[] = $condition->getConditionTargetRefId();
                } catch (\Throwable $t) {
                    continue;
                }
            }
        }
        return $targets;
    }

    /**
     * @return \ILIAS\LearningSequence\Content\Condition\AbstractCondition[]
     */
    protected function getConditionsFor(int $item_ref_id): array
    {
        $conditions = [];
        foreach ($this->discoverer->getAllConditionIdsForItem($item_ref_id) as $condition_id) {
            try {
                $conditions[] = $this->condition_factory->getConditionInstanceById($condition_id);
            } catch (\Throwable $t) {
                continue;
            }
        }
        return $conditions;
    }
}
