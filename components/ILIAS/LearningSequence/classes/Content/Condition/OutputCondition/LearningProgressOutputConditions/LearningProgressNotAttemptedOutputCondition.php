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

namespace ILIAS\LearningSequence\Content\Condition\OutputCondition\LearningProgressOutputConditions;

use ILIAS\LearningSequence\Content\Condition\AbstractLeafCondition;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;
use ilLPStatus;

final class LearningProgressNotAttemptedOutputCondition extends AbstractLeafCondition implements OutputConditionInterface
{
    final protected const NAME = "learning_progress_not_attempted";
    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        return ilLPStatus::_lookupStatus(
            $this->obj_ref_id,
            $this->dic->user()->getId()
        ) === ilLPStatus::LP_STATUS_NOT_ATTEMPTED;
    }

    public static function migrate(): array
    {
        return [];
    }

    public function setupSteps(): array
    {
        $this->assertContextSet();
        return parent::setupSteps();
    }

}
