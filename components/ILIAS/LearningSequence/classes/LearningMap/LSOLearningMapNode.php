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

namespace ILIAS\LearningSequence\LearningMap;

/**
 * Immutable data-transfer object describing exactly one object within the LSO
 * for the map view.
 *
 * A node carries everything the (JS) map UI needs to draw the object and its
 * state for a given learner: its identity (obj_id), its content (title,
 * description), its state (visited/completed/situation/visit_count) and its
 * outgoing edges (successors). Layout is deliberately NOT part of this DTO; the
 * $depth field is only an unbinding hint.
 */
final class LSOLearningMapNode
{
    /**
     * @param int    $obj_id               unique address of the object within the LSO
     * @param string $title                display title of the object
     * @param string $description          display description of the object
     * @param string $icon                 path to the object type's icon (as delivered
     *                                      by LSItem::getIconPath()), '' if none
     * @param string|null $player_link     link that jumps the player to this object,
     *                                      or null if the learner has no access yet
     * @param bool   $can_access           whether the learner may enter (all incoming
     *                                      predecessors' output-conditions fulfilled)
     * @param bool   $has_visited          whether the learner ever visited this object
     * @param bool   $has_completed        whether the object's output-conditions are met
     * @param bool   $can_leave            whether the learner may advance FROM this object,
     *                                      i.e. all of its output-conditions (e.g. learning
     *                                      progress "completed") are fulfilled. Every outgoing
     *                                      edge is passable only if this is true.
     * @param string $situation            one of start/end/branch/straight/deadend/blocked
     * @param int[]  $successors           obj_ids of the directly reachable follow-up objects
     * @param int[]  $passable_successors  obj_ids of those successors whose edge may be
     *                                      used right now: this object may be left AND the
     *                                      target may be entered coming from this edge.
     *                                      Everything in $successors but not in here is
     *                                      drawn as a blocked edge.
     * @param int[]  $input_condition_ids  ids of ALL input-conditions of the object
     * @param int[]  $output_condition_ids ids of ALL output-conditions of the object
     * @param int    $visit_count          how often the learner visited this object
     * @param int|null $last_visited_ts    Unix timestamp of the most recent visit,
     *                                      or null if the learner never visited it
     * @param bool   $is_current           whether the learner currently stands on this node
     * @param bool   $is_on_walked_path    whether the node lies on the currently active path
     * @param int    $depth                waterfall depth hint (layout is up to the UI)
     */
    public function __construct(
        public readonly int $obj_id,
        public readonly string $title,
        public readonly string $description,
        public readonly string $icon,
        public readonly ?string $player_link,
        public readonly bool $can_access,
        public readonly bool $has_visited,
        public readonly bool $has_completed,
        public readonly bool $can_leave,
        public readonly string $situation,
        public readonly array $successors,
        public readonly array $passable_successors,
        public readonly array $input_condition_ids,
        public readonly array $output_condition_ids,
        public readonly int $visit_count,
        public readonly ?int $last_visited_ts,
        public readonly bool $is_current,
        public readonly bool $is_on_walked_path,
        public readonly int $depth
    ) {
    }

    /**
     * Plain-array representation, ready to be json_encode()'d and handed to the
     * JS map library. The keys are the stable contract of the map data layer.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'obj_id' => $this->obj_id,
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,
            'player_link' => $this->player_link,
            'can_access' => $this->can_access,
            'has_visited' => $this->has_visited,
            'has_completed' => $this->has_completed,
            'can_leave' => $this->can_leave,
            'situation' => $this->situation,
            'successors' => array_values($this->successors),
            'passable_successors' => array_values($this->passable_successors),
            'input_condition_ids' => array_values($this->input_condition_ids),
            'output_condition_ids' => array_values($this->output_condition_ids),
            'visit_count' => $this->visit_count,
            'last_visited_ts' => $this->last_visited_ts,
            'is_current' => $this->is_current,
            'is_on_walked_path' => $this->is_on_walked_path,
            'depth' => $this->depth
        ];
    }
}
