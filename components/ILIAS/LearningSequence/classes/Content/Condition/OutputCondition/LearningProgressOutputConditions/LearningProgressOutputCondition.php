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
                (new LearningProgressNotAttemptedOutputCondition($this->lso_ref_id, $this->obj_ref_id))->getStep(),
                (new LearningProgressInProgressOutputCondition($this->lso_ref_id, $this->obj_ref_id))->getStep(),
                (new LearningProgressCompletedOutputCondition($this->lso_ref_id, $this->obj_ref_id))->getStep(),
                (new LearningProgressFailedOutputCondition($this->lso_ref_id, $this->obj_ref_id))->getStep()
            ])
        ];
    }

    public function check(): bool
    {
        return false;
    }
    public function migrate(): array
    {
        return [];
    }

    public function getName(): ?string
    {
        return "";
    }
}
