<?php

namespace ILIAS\LearningSequence\Content\Condition\OutputCondition\LearningProgress;

use ILIAS\LearningSequence\Content\Condition\ConditionAbstract;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;

class LearningProgressCondition extends ConditionAbstract implements OutputConditionInterface
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
