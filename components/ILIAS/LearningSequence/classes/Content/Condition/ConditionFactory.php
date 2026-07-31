<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Condition;

class ConditionFactory
{
    public static function instantiateByName(string $condition_name, string $type): AbstractCondition
    {
        $class_name = "ILIAS\\LearningSequence\\Content\\Condition\\{$type}\\{$condition_name}Condition";

        if (class_exists($class_name)) {
            $condition = new $class_name();
        } else {
            throw new \InvalidArgumentException("Class {$class_name} does not exist.");
        }

        return $condition;
    }
}