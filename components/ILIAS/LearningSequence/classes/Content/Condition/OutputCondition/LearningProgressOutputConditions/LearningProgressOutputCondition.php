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

use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;

final class LearningProgressOutputCondition extends AbstractCondition implements OutputConditionInterface
{
    protected const NAME = 'learning_progress';

    /**
     * @inheritDoc
     */
    public function setupSteps(): array
    {
        return [
            $this->ui_factory->menu()->sub($this->getName(), [
                (new LearningProgressNotAttemptedOutputCondition())->getStep(),
                (new LearningProgressInProgressOutputCondition())->getStep(),
                (new LearningProgressCompletedOutputCondition())->getStep(),
                (new LearningProgressFailedOutputCondition())->getStep()
            ])
        ];
    }

    public function check(): bool
    {
        return false;
    }

    public static function migrate(): array
    {
        return [];
    }

    public function getName(): ?string
    {
        return self::NAME;
    }
}
