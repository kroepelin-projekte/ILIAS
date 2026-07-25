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

final readonly class ilObjLearningSequenceContentData
{
    public function __construct(
        public int $obj_id,
        public string $title,
        public string $description,
        public string $type,
        public string $icon_path,
        public string $href,
        public bool $is_online,
        public string $start_object,
        public string $end_object,
        public string $previous_objects,
        public string $next_objects,
        public array $input_conditions,
        public array $output_conditions,
        public array $actions = []
    ) {
    }
}
