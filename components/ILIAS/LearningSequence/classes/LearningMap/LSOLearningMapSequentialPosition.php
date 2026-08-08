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

class LSOLearningMapSequentialPosition extends LSOLearningMapPosition
{
    protected int $first_ref_id = 0;
    protected int $last_ref_id = 0;

    public function prepareForItems(array $items): void
    {
        $items = array_values($items);
        if ($items === []) {
            $this->first_ref_id = 0;
            $this->last_ref_id = 0;
            return;
        }

        $this->first_ref_id = $items[0]->getRefId();
        $this->last_ref_id = $items[count($items) - 1]->getRefId();
    }

    protected function getStartRefId(): int
    {
        return $this->first_ref_id;
    }

    protected function getEndRefId(): int
    {
        return $this->last_ref_id;
    }

    public function hasCompleted(array $items, int $obj_id): bool
    {
        if ($obj_id === 0) {
            return false;
        }
        foreach ($items as $item) {
            if ($this->lookupObjId($item->getRefId()) === $obj_id) {
                return $this->navigator->canLeave($item);
            }
        }
        return false;
    }
}
