<?php

namespace ILIAS\LearningSequence\Content\Condition\InputCondition\LogicGatter;

use ILIAS\LearningSequence\Content\Condition\ConditionHandler;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;

class LogicGatterCondition extends ConditionHandler implements InputConditionInterface
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
