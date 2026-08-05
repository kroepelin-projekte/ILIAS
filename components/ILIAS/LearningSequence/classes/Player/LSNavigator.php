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
 * Abstraction of the navigation between the objects of a learning sequence.
 *
 * The linear (index based) and the adaptive (condition based) navigation both
 * implement this interface so that the player itself does not need to know the
 * concrete strategy.
 */
interface LSNavigator
{
    /**
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[] the objects one may continue with from $current
     */
    public function getSuccessors(array $items, \LSLearnerItem $current): array;

    /**
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[] the objects one came from before $current
     */
    public function getPredecessors(array $items, \LSLearnerItem $current): array;

    /**
     * Whether the current object may be left (output-condition).
     */
    public function canLeave(\LSLearnerItem $current): bool;

    /**
     * Whether the target object may be entered (input-condition).
     */
    public function canEnter(\LSLearnerItem $target): bool;
}
