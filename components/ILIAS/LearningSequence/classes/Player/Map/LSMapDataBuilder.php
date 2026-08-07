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

namespace ILIAS\LearningSequence\Player\Map;

use ILIAS\LearningSequence\Player\AdaptiveNavigator;

/**
 * Aggregator/assembler that turns the existing adaptive-navigation state
 * (LSAdaptivePosition + AdaptiveNavigator) into the pure map data structure
 * (LSMap / LSMapNode[]) for a given learner.
 *
 * It builds a directed graph by a breadth-first traversal starting at the LSO's
 * start object, following the allowed successors. A visited-set guards against
 * cycles ("Ehrenrunden", e.g. jumping back to an earlier object after failing a
 * test), so the traversal always terminates and every object becomes exactly
 * one node (with possibly several incoming edges).
 *
 * The builder does NOT render anything and does NOT compute a layout; it only
 * fills the DTOs. The waterfall layout is up to the (JS) map UI.
 */
class LSMapDataBuilder
{
    /**
     * The position (visit log, walked path, completion) and the learner items
     * are both user-specific, so they are NOT injected ready-made but created
     * per user_id through the two factory closures below. This lets the caller
     * request the map of ANY learner via build($mode, $usr_id) - e.g. a future
     * tutor view that inspects the maps of all participants - instead of being
     * locked to the current user.
     *
     * @param \Closure(int): LSAdaptivePosition $position_factory
     *        fn(int $usr_id): LSAdaptivePosition
     * @param \Closure(int): \LSLearnerItem[]   $items_factory
     *        fn(int $usr_id): \LSLearnerItem[]
     */
    public function __construct(
        protected AdaptiveNavigator $navigator,
        protected \LSUrlBuilder $url_builder,
        protected string $goto_command,
        protected int $lso_obj_id,
        protected int $default_usr_id,
        protected \Closure $position_factory,
        protected \Closure $items_factory
    ) {
    }

    /**
     * Builds the map for the given view mode and - optionally - a specific
     * learner. If $usr_id is null the current user (default_usr_id) is used;
     * pass an explicit user_id to build the map of another learner (tutor view).
     */
    public function build(int $mode, ?int $usr_id = null): LSMap
    {
        if (!LSMapViewMode::isValid($mode)) {
            $mode = LSMapViewMode::MODE_FULL_ROUTE;
        }

        $usr_id = $usr_id ?? $this->default_usr_id;
        $position = ($this->position_factory)($usr_id);
        $items = ($this->items_factory)($usr_id);

        $start_obj_id = $position->getStartObjId();
        $end_obj_id = $position->getEndObjId();
        $current_obj_id = $position->getCurrentObjId();
        $walked_obj_ids = $position->getWalkedObjIds();

        $start_item = $this->resolveStartItem($items, $start_obj_id);
        if ($start_item === null) {
            return new LSMap($this->lso_obj_id, $usr_id, $mode, $start_obj_id, $end_obj_id, []);
        }
        // the effective start obj_id (may fall back to the first item)
        $start_obj_id = \ilObject::_lookupObjId($start_item->getRefId());

        $nodes = $this->traverse($position, $items, $start_item, $start_obj_id, $end_obj_id, $current_obj_id, $walked_obj_ids);
        $nodes = $this->applyViewMode($nodes, $mode);

        return new LSMap($this->lso_obj_id, $usr_id, $mode, $start_obj_id, $end_obj_id, $nodes);
    }

    /**
     * Breadth-first traversal from the start object over the allowed
     * successors. Uses a visited-set (keyed by obj_id) so cycles/back-jumps
     * terminate; every object becomes exactly one node.
     *
     * @param \LSLearnerItem[] $items
     * @param int[] $walked_obj_ids
     * @return LSMapNode[] indexed by obj_id
     */
    protected function traverse(
        LSAdaptivePosition $position,
        array $items,
        \LSLearnerItem $start_item,
        int $start_obj_id,
        int $end_obj_id,
        int $current_obj_id,
        array $walked_obj_ids
    ): array {
        $nodes = [];
        $depth = [$start_obj_id => 0];
        $queue = [[$start_item, $start_obj_id]];
        $visited = [$start_obj_id => true];
        $roots = $this->collectAdditionalRoots($items, $visited);

        while ($queue !== [] || $roots !== []) {
            if ($queue === [] && $roots !== []) {
                // an object that is not connected to the start object at all:
                // it is shown as its own root so nothing gets hidden
                [$root_item, $root_obj_id] = array_shift($roots);
                if (isset($visited[$root_obj_id])) {
                    continue;
                }
                $visited[$root_obj_id] = true;
                $depth[$root_obj_id] = 0;
                $queue[] = [$root_item, $root_obj_id];
            }
            [$item, $obj_id] = array_shift($queue);

            $successor_items = $position->getStructuralSuccessors($items, $item);
            $successor_obj_ids = [];
            // the edges that may be used right now: the object itself may be
            // left AND the target may be entered coming from exactly this edge
            $passable_successor_obj_ids = [];
            $can_leave = $this->navigator->canLeave($item);
            foreach ($successor_items as $successor) {
                $successor_obj_id = \ilObject::_lookupObjId($successor->getRefId());
                $successor_obj_ids[] = $successor_obj_id;
                if ($can_leave && $this->navigator->canEnterFrom($item, $successor)) {
                    $passable_successor_obj_ids[] = $successor_obj_id;
                }
                if (!isset($visited[$successor_obj_id])) {
                    $visited[$successor_obj_id] = true;
                    $depth[$successor_obj_id] = ($depth[$obj_id] ?? 0) + 1;
                    $queue[] = [$successor, $successor_obj_id];
                }
            }

            $nodes[$obj_id] = $this->buildNode(
                $position,
                $items,
                $item,
                $obj_id,
                $successor_obj_ids,
                $passable_successor_obj_ids,
                $start_obj_id,
                $end_obj_id,
                $current_obj_id,
                $walked_obj_ids,
                $depth[$obj_id] ?? 0
            );
        }

        return $nodes;
    }

    /**
     * All items of the learning sequence as potential traversal roots, so that
     * objects which are not (yet) connected to the start object still become
     * nodes of the map instead of being dropped silently.
     *
     * @param \LSLearnerItem[] $items
     * @param array<int, bool> $visited
     * @return array<int, array{0: \LSLearnerItem, 1: int}>
     */
    protected function collectAdditionalRoots(array $items, array $visited): array
    {
        $roots = [];
        foreach ($items as $item) {
            $obj_id = \ilObject::_lookupObjId($item->getRefId());
            if (isset($visited[$obj_id])) {
                continue;
            }
            $roots[] = [$item, $obj_id];
        }
        return $roots;
    }

    /**
     * @param \LSLearnerItem[] $items
     * @param int[] $successor_obj_ids
     * @param int[] $passable_successor_obj_ids
     * @param int[] $walked_obj_ids
     */
    protected function buildNode(
        LSAdaptivePosition $position,
        array $items,
        \LSLearnerItem $item,
        int $obj_id,
        array $successor_obj_ids,
        array $passable_successor_obj_ids,
        int $start_obj_id,
        int $end_obj_id,
        int $current_obj_id,
        array $walked_obj_ids,
        int $depth
    ): LSMapNode {
        $can_access = $this->canAccess($items, $item, $obj_id, $start_obj_id);
        if (!$can_access) {
            // an object the learner may not even enter cannot be left either,
            // so all of its outgoing edges are blocked as well
            $passable_successor_obj_ids = [];
        }

        return new LSMapNode(
            obj_id: $obj_id,
            title: $item->getTitle(),
            description: $item->getDescription(),
            icon: $item->getIconPath(),
            player_link: $can_access
                ? $this->url_builder->getHref($this->goto_command, $obj_id)
                : null,
            can_access: $can_access,
            has_visited: $position->hasVisited($obj_id),
            // an object that may not be entered cannot have been completed;
            // without this guard objects without any output-condition on a
            // blocked branch would show up as "done"
            has_completed: $can_access && $position->hasCompleted($items, $obj_id),
            // may the learner advance FROM here? Purely the object's own
            // output-conditions (e.g. learning progress "completed"); the map
            // draws every outgoing edge as blocked while this is false.
            can_leave: $this->navigator->canLeave($item),
            situation: $obj_id === $start_obj_id
                ? 'start'
                : $position->getStructuralSituation($items, $item),
            successors: $successor_obj_ids,
            passable_successors: $passable_successor_obj_ids,
            input_condition_ids: $this->navigator->getInputConditionIds($item),
            output_condition_ids: $this->navigator->getOutputConditionIds($item),
            visit_count: $position->getVisitCount($obj_id),
            last_visited_ts: $position->getLastVisitTs($obj_id),
            is_current: $obj_id === $current_obj_id,
            is_on_walked_path: in_array($obj_id, $walked_obj_ids, true),
            depth: $depth
        );
    }

    /**
     * can_access is defined via the output-conditions of the predecessors: a
     * node is accessible as soon as AT LEAST ONE of its incoming predecessors
     * may be left (its output-conditions are fulfilled), because several
     * incoming edges describe alternative paths (e.g. P1 or P2 lead to the
     * goal). Additionally all input-conditions that are not edges themselves
     * must be fulfilled. The start object has no predecessors and is therefore
     * always accessible.
     */
    protected function canAccess(array $items, \LSLearnerItem $item, int $obj_id, int $start_obj_id): bool
    {
        if ($obj_id === $start_obj_id) {
            return true;
        }
        if (!$this->navigator->canEnterIgnoringEdges($item)) {
            return false;
        }
        $predecessors = $this->navigator->getPredecessors($items, $item);
        if ($predecessors === []) {
            return true;
        }
        foreach ($predecessors as $predecessor) {
            if ($this->navigator->canLeave($predecessor)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Applies the view mode as a pure view filter (never a permission filter).
     * Edges pointing at removed nodes are pruned so the returned graph stays
     * consistent.
     *
     * @param LSMapNode[] $nodes
     * @return LSMapNode[]
     */
    protected function applyViewMode(array $nodes, int $mode): array
    {
        if ($mode !== LSMapViewMode::MODE_REACHABLE_ONLY) {
            // FULL_ROUTE and PROGRESS keep every node (PROGRESS is a highlight
            // hint for the UI, not a filter).
            return $nodes;
        }

        // Only reachable nodes: accessible now, already visited (backwards
        // reachable "Ehrenrunden") or the current node.
        $kept = [];
        foreach ($nodes as $obj_id => $node) {
            if ($node->can_access || $node->has_visited || $node->is_current) {
                $kept[$obj_id] = $node;
            }
        }

        return $this->pruneDanglingEdges($kept);
    }

    /**
     * Removes successor edges that point at nodes which are no longer part of
     * the (filtered) node set.
     *
     * @param LSMapNode[] $nodes
     * @return LSMapNode[]
     */
    protected function pruneDanglingEdges(array $nodes): array
    {
        $pruned = [];
        foreach ($nodes as $obj_id => $node) {
            $successors = array_values(array_filter(
                $node->successors,
                static fn(int $successor_obj_id): bool => isset($nodes[$successor_obj_id])
            ));
            $passable_successors = array_values(array_filter(
                $node->passable_successors,
                static fn(int $successor_obj_id): bool => isset($nodes[$successor_obj_id])
            ));
            $pruned[$obj_id] = new LSMapNode(
                obj_id: $node->obj_id,
                title: $node->title,
                description: $node->description,
                icon: $node->icon,
                player_link: $node->player_link,
                can_access: $node->can_access,
                has_visited: $node->has_visited,
                has_completed: $node->has_completed,
                can_leave: $node->can_leave,
                situation: $node->situation,
                successors: $successors,
                passable_successors: $passable_successors,
                input_condition_ids: $node->input_condition_ids,
                output_condition_ids: $node->output_condition_ids,
                visit_count: $node->visit_count,
                last_visited_ts: $node->last_visited_ts,
                is_current: $node->is_current,
                is_on_walked_path: $node->is_on_walked_path,
                depth: $node->depth
            );
        }
        return $pruned;
    }

    /**
     * Resolves the item the traversal starts from: the configured start object
     * if available, otherwise the first item as a fallback (mirrors
     * LSAdaptivePosition::getCurrentItem).
     */
    protected function resolveStartItem(array $items, int $start_obj_id): ?\LSLearnerItem
    {
        if ($items === []) {
            return null;
        }
        if ($start_obj_id !== 0) {
            foreach ($items as $item) {
                if (\ilObject::_lookupObjId($item->getRefId()) === $start_obj_id) {
                    return $item;
                }
            }
        }
        return array_values($items)[0];
    }
}
