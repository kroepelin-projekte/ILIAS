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
 * The learner's position within a sequential learning sequence.
 *
 * The sequential mode has neither a configurable start/end object nor a walked
 * path or visit log - all of that belongs to the adaptive mode. What it does
 * have is the order of the objects, so the first object is the start and the
 * last one is the end of the sequence.
 *
 * Everything the sequential mode simply does not provide (current position,
 * walked path, visit statistics) stays empty on purpose; the map just does not
 * show it.
 */
class LSOLearningMapSequentialPosition extends LSOLearningMapPosition
{
    protected int $first_ref_id = 0;
    protected int $last_ref_id = 0;

    /**
     * Start and end are derived from the order of the objects.
     *
     * @param \LSLearnerItem[] $items
     */
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
}
