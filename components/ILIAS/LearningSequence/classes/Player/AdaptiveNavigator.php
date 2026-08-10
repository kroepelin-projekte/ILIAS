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
 * Provides condition-based navigation through an adaptive learning sequence.
 */
class AdaptiveNavigator implements LSNavigator
{
    /**
     * Discovers the conditions assigned to learning-sequence items.
     */
    protected ilObjLearningSequenceConditionDiscover $discoverer;
    /**
     * Creates condition instances from their identifiers.
     */
    protected ConditionFactory $condition_factory;

    /**
     * @var array<int, AbstractCondition[]> Condition instances indexed by item reference identifier.
     */
    protected array $conditions_cache = [];
    /**
     * @var array<int, int[]> Source reference identifiers indexed by target item reference identifier.
     */
    protected array $edge_targets_cache = [];
    /**
     * @var array<int, int[]> Synthetic successor reference identifiers for points-based unlocks.
     */
    protected array $points_structural_successors_cache = [];
    /**
     * @var array<int, int[]> Synthetic predecessor reference identifiers for points-based unlocks.
     */
    protected array $points_structural_predecessors_cache = [];
    /**
     * @var array<int, \LSLearnerItem> Items indexed by reference identifier.
     */
    protected array $items_by_ref_id = [];
    /**
     * @var int Maximum sum of all configurable points outputs in the sequence.
     */
    protected int $max_points_budget = 0;
    /**
     * @var array<int, bool> Whether an item may be left, indexed by item reference identifier.
     */
    protected array $can_leave_cache = [];
    /**
     * @var array<int, bool> Whether an item may be entered without edge conditions, indexed by item reference identifier.
     */
    protected array $can_enter_ignoring_edges_cache = [];

    /**
     * Creates an adaptive navigator with the supplied or default condition services.
     */
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
     * Preloads condition instances for the given items.
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

        $this->buildPointsNavigationCaches($items);
    }

    /**
     * Returns the items connected to and enterable from the current item.
     *
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
            if ($this->isEdge($current->getRefId(), $item->getRefId())) {
                if ($this->canEnterFrom($current, $item)) {
                    $successors[$item->getRefId()] = $item;
                }
                continue;
            }

            if ($this->isPointsGlobalSuccessorTarget($item->getRefId()) && $this->canEnter($item)) {
                $successors[$item->getRefId()] = $item;
            }
        }
        return array_values($successors);
    }

    /**
     * Returns the items structurally connected to the current item.
     *
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
            if (
                $this->isEdge($current->getRefId(), $item->getRefId())
                || in_array($item->getRefId(), $this->getPointsStructuralSuccessorsFor($current->getRefId()), true)
            ) {
                $successors[] = $item;
            }
        }
        return $successors;
    }

    /**
     * Returns the items that are connected to the current item by an incoming edge.
     *
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getPredecessors(array $items, \LSLearnerItem $current): array
    {
        $predecessor_ref_ids = array_values(array_unique(array_merge(
            $this->getEdgeTargetsFor($current->getRefId()),
            $this->getPointsStructuralPredecessorsFor($current->getRefId())
        )));
        $predecessors = [];
        foreach ($items as $item) {
            if (in_array($item->getRefId(), $predecessor_ref_ids, true)) {
                $predecessors[] = $item;
            }
        }
        return $predecessors;
    }

    /**
     * Determines whether all output conditions of the current item are met.
     */
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

    /**
     * Determines whether all input conditions of the target item are met.
     */
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
     * Determines whether the target is enterable without learning-progress edge conditions.
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
     * Determines whether the target is enterable from the current item.
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
     * Returns the identifiers of input conditions assigned to an item.
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
     * Returns the identifiers of output conditions assigned to an item.
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
     * Evaluates a condition and treats evaluation failures as unmet conditions.
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
     * Determines whether an edge connects two items.
     */
    protected function isEdge(int $from_ref_id, int $to_ref_id): bool
    {
        return in_array($from_ref_id, $this->getEdgeTargetsFor($to_ref_id), true);
    }

    /**
     * Returns the source reference identifiers of edges leading to an item.
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
     * Precomputes synthetic points-based unlock edges for map rendering.
     *
     * @param \LSLearnerItem[] $items
     */
    protected function buildPointsNavigationCaches(array $items): void
    {
        $this->items_by_ref_id = [];
        $this->points_structural_successors_cache = [];
        $this->points_structural_predecessors_cache = [];
        $this->max_points_budget = 0;

        foreach ($items as $item) {
            $ref_id = $item->getRefId();
            $this->items_by_ref_id[$ref_id] = $item;
            $this->points_structural_successors_cache[$ref_id] = [];
            $this->points_structural_predecessors_cache[$ref_id] = [];
            $this->max_points_budget += $this->getConfiguredPointsOutput($ref_id);
        }

        if ($items === []) {
            return;
        }

        $queue = [];
        $seen_states = [];
        foreach ($items as $item) {
            $ref_id = $item->getRefId();
            if ($this->hasLearningProgressIncomingEdges($ref_id)) {
                continue;
            }
            if (!$this->canStructurallyEnterWithAvailablePoints($ref_id, 0)) {
                continue;
            }

            $points_after = $this->getConfiguredPointsOutput($ref_id);
            $seen_states[$ref_id][$points_after] = true;
            $queue[] = [$ref_id, $points_after];
        }

        while ($queue !== []) {
            [$current_ref_id, $current_points_after] = array_shift($queue);
            $current_points_before = max(0, $current_points_after - $this->getConfiguredPointsOutput($current_ref_id));

            foreach ($items as $target) {
                $target_ref_id = $target->getRefId();
                if ($target_ref_id === $current_ref_id) {
                    continue;
                }

                if ($this->isEdge($current_ref_id, $target_ref_id)) {
                    if ($this->canStructurallyEnterWithAvailablePoints($target_ref_id, $current_points_after)) {
                        $candidate_points = min(
                            $this->max_points_budget,
                            $current_points_after + $this->getConfiguredPointsOutput($target_ref_id)
                        );
                        if (!isset($seen_states[$target_ref_id][$candidate_points])) {
                            $seen_states[$target_ref_id][$candidate_points] = true;
                            $queue[] = [$target_ref_id, $candidate_points];
                        }
                    }
                    continue;
                }

                $required_points = $this->getPurePointsRequirement($target_ref_id);
                if ($required_points === null || $required_points === 0) {
                    continue;
                }
                if ($current_points_before >= $required_points || $current_points_after < $required_points) {
                    continue;
                }

                $this->addPointsStructuralEdge($current_ref_id, $target_ref_id);

                $candidate_points = min(
                    $this->max_points_budget,
                    $current_points_after + $this->getConfiguredPointsOutput($target_ref_id)
                );
                if (!isset($seen_states[$target_ref_id][$candidate_points])) {
                    $seen_states[$target_ref_id][$candidate_points] = true;
                    $queue[] = [$target_ref_id, $candidate_points];
                }
            }
        }
    }

    protected function addPointsStructuralEdge(int $from_ref_id, int $to_ref_id): void
    {
        if (!in_array($to_ref_id, $this->points_structural_successors_cache[$from_ref_id] ?? [], true)) {
            $this->points_structural_successors_cache[$from_ref_id][] = $to_ref_id;
        }
        if (!in_array($from_ref_id, $this->points_structural_predecessors_cache[$to_ref_id] ?? [], true)) {
            $this->points_structural_predecessors_cache[$to_ref_id][] = $from_ref_id;
        }
    }

    /**
     * Returns whether the target behaves like a global points gate in the player.
     */
    protected function isPointsGlobalSuccessorTarget(int $target_ref_id): bool
    {
        return !$this->hasLearningProgressIncomingEdges($target_ref_id) && $this->hasPointsInputCondition($target_ref_id);
    }

    /**
     * Returns whether the item can be entered using points-only structural data.
     */
    protected function canStructurallyEnterWithAvailablePoints(int $target_ref_id, int $available_points): bool
    {
        $required_points = $this->getPurePointsRequirement($target_ref_id);
        if ($required_points === null) {
            return false;
        }

        return $available_points >= $required_points;
    }

    /**
     * Returns the required points if the item's non-edge inputs are points-only.
     */
    protected function getPurePointsRequirement(int $target_ref_id): ?int
    {
        $required_points = 0;
        foreach ($this->getConditionsFor($target_ref_id) as $condition) {
            if ($condition instanceof LearningProgressInputAwareCondition) {
                continue;
            }
            if ($this->isPointsInputCondition($condition)) {
                $required_points = max($required_points, $condition->getPoints());
                continue;
            }
            if ($condition instanceof InputConditionInterface) {
                return null;
            }
        }

        return $required_points;
    }

    protected function hasPointsInputCondition(int $target_ref_id): bool
    {
        foreach ($this->getConditionsFor($target_ref_id) as $condition) {
            if ($this->isPointsInputCondition($condition)) {
                return true;
            }
        }

        return false;
    }

    protected function hasLearningProgressIncomingEdges(int $target_ref_id): bool
    {
        return $this->getEdgeTargetsFor($target_ref_id) !== [];
    }

    protected function getConfiguredPointsOutput(int $item_ref_id): int
    {
        $points = 0;
        foreach ($this->getConditionsFor($item_ref_id) as $condition) {
            if ($this->isPointsOutputCondition($condition)) {
                $points += $condition->getPoints();
            }
        }

        return $points;
    }

    /**
     * @return int[]
     */
    protected function getPointsStructuralSuccessorsFor(int $item_ref_id): array
    {
        return $this->points_structural_successors_cache[$item_ref_id] ?? [];
    }

    /**
     * @return int[]
     */
    protected function getPointsStructuralPredecessorsFor(int $item_ref_id): array
    {
        return $this->points_structural_predecessors_cache[$item_ref_id] ?? [];
    }

    protected function isPointsInputCondition(AbstractCondition $condition): bool
    {
        return $condition instanceof InputConditionInterface && $condition->getName() === 'points_input';
    }

    protected function isPointsOutputCondition(AbstractCondition $condition): bool
    {
        return $condition instanceof OutputConditionInterface && $condition->getName() === 'points_output';
    }

    /**
     * Returns the condition instances assigned to an item.
     *
     * @return AbstractCondition[]
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
