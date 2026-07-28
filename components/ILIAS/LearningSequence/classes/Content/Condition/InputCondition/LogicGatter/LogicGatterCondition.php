<?php

namespace ILIAS\LearningSequence\Content\Condition\InputCondition\LogicGatter;

use ILIAS\LearningSequence\Content\Condition\ConditionAbstract;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;

class LogicGatterCondition extends ConditionAbstract implements InputConditionInterface
{
    public function getName(): string
    {
        return "inputLogicGatter";
    }

    public function migrate(): array
    {
        return [];
    }
}
