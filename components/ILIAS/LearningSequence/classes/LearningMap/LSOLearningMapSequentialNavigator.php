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

use ILIAS\LearningSequence\Player\LinearNavigator;

/**
 * Navigation of the sequential mode as the map needs it.
 *
 * The order of the objects is the plain list order, exactly like in the
 * LinearNavigator (which the player uses). In contrast to the player - which
 * relies on the ILIAS condition subsystem for the access check - the map has to
 * know per object whether the learner may advance from it. The sequential mode
 * expresses this with the post-condition of the object itself
 * (ilLSPostCondition): either "always" or "according to the learning progress".
 *
 * The navigator therefore only overrides the "may I leave?" part and stays
 * read-only: nothing about the sequential mode itself is changed.
 */
class LSOLearningMapSequentialNavigator extends LinearNavigator
{
    /**
     * May the learner advance from the given object? Evaluated purely on the
     * object's own post-condition and the learning progress the learner item
     * already carries (ilLearnerProgressDB), so no additional query is needed.
     */
    public function canLeave(\LSLearnerItem $current): bool
    {
        $condition = $current->getPostCondition();
        $status = $current->getLearningProgressStatus();

        return match ($condition->getConditionOperator()) {
            \ilLSPostCondition::OPERATOR_ALWAYS => true,
            \ilLSPostCondition::OPERATOR_FAILED => $status === \ilLPStatus::LP_STATUS_FAILED_NUM,
            \ilLSPostCondition::OPERATOR_NOT_FINISHED => $status !== \ilLPStatus::LP_STATUS_COMPLETED_NUM,
            // learning_progress, finished and passed all boil down to
            // "the object has been completed"
            default => $status === \ilLPStatus::LP_STATUS_COMPLETED_NUM,
        };
    }

    /**
     * An object may be entered as soon as the object it is reached from may be
     * left; there are no input-conditions in the sequential mode.
     */
    public function canEnterFrom(\LSLearnerItem $current, \LSLearnerItem $target): bool
    {
        return $this->canLeave($current);
    }
}
