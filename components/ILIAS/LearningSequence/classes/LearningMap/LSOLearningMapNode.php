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

final class LSOLearningMapNode
{
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
