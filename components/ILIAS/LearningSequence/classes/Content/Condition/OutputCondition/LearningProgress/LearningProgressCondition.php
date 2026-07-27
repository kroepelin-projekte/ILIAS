<?php

namespace ILIAS\LearningSequence\Content\Condition\OutputCondition\LearningProgress;

use ILIAS\LearningSequence\Content\Condition\ConditionHandler;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;

class LearningProgressCondition extends ConditionHandler implements OutputConditionInterface
{
    public function getName(): string
    {
        return "outputLearningProgress";
    }

    public function migrate(): array
    {
        return [];
    }
}
