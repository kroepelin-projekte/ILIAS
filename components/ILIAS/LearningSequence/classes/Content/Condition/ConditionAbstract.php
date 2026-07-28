<?php
declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Condition;

/**
 * Abstract base class for all Learning Sequence Conditions
 */
abstract class ConditionAbstract
{
    /**
     * Get the internal unique name of the condition
     */
    abstract public function getName(): string;

    /**
     * Define the database schema required by this condition
     * @return TableDefinition[]
     */
    abstract public function migrate(): array;
}
