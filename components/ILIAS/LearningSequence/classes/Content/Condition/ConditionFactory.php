<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Condition;

class ConditionFactory
{
    public static function instantiateByType(string $type): AbstractCondition
    {
        $abstract_condition = new AbstractCondition();
        $types =
    }

    private static function getTypes()
    {
        global $DIC;
        $db = $DIC->database();

        $res = $db->queryF(
            'SELECT con FROM lso_condition_types WHERE condition_name = %s',
            ['text'],
            [$this->getName()]
        );
        $row = $this->getDatabase()->fetchAssoc($res);
    }
}