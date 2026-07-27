<?php

namespace ILIAS\LearningSequence\Content\Condition;

/**
 * Data object for table definitions used in condition migrations.
 */
final readonly class TableDefinition
{
    public function __construct(
        public string $tableName,
        public array $fields,
        public array $primaryKeys = [],
        public bool $hasSequence = false
    ) {
    }
}
