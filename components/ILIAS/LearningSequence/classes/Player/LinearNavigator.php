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

/**
 * Linear navigation: the objects are traversed in their fixed list order.
 *
 * This encapsulates the historic index-based logic of the player 1:1. There
 * are never any conditions in the linear mode, therefore leaving and entering
 * an object is always allowed.
 */
class LinearNavigator implements LSNavigator
{
    /**
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getSuccessors(array $items, \LSLearnerItem $current): array
    {
        $position = $this->findPosition($items, $current);
        $next = $position + 1;
        if ($next >= 0 && $next < count($items)) {
            return [$items[$next]];
        }
        return [];
    }

    /**
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getStructuralSuccessors(array $items, \LSLearnerItem $current): array
    {
        return $this->getSuccessors($items, $current);
    }

    /**
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getPredecessors(array $items, \LSLearnerItem $current): array
    {
        $position = $this->findPosition($items, $current);
        $previous = $position - 1;
        if ($previous >= 0 && $previous < count($items)) {
            return [$items[$previous]];
        }
        return [];
    }

    public function canLeave(\LSLearnerItem $current): bool
    {
        return true;
    }

    public function canEnter(\LSLearnerItem $target): bool
    {
        return true;
    }

    public function canEnterIgnoringEdges(\LSLearnerItem $target): bool
    {
        return true;
    }

    public function canEnterFrom(\LSLearnerItem $current, \LSLearnerItem $target): bool
    {
        return true;
    }

    /**
     * Nothing to preload: the linear navigation is purely index based.
     *
     * @param \LSLearnerItem[] $items
     */
    public function preload(array $items): void
    {
    }

    /**
     * There are no conditions in the linear mode.
     *
     * @return int[]
     */
    public function getInputConditionIds(\LSLearnerItem $item): array
    {
        return [];
    }

    /**
     * There are no conditions in the linear mode.
     *
     * @return int[]
     */
    public function getOutputConditionIds(\LSLearnerItem $item): array
    {
        return [];
    }

    /**
     * @param \LSLearnerItem[] $items
     */
    protected function findPosition(array $items, \LSLearnerItem $item): int
    {
        foreach ($items as $index => $candidate) {
            if ($candidate->getRefId() === $item->getRefId()) {
                return $index;
            }
        }
        throw new \Exception("This is not a valid item.", 1);
    }
}
