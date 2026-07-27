<?php

namespace ILIAS\LearningSequence\Content\Condition\InputCondition\Always;

use ILIAS\LearningSequence\Content\Condition\ConditionHandler;
use ILIAS\LearningSequence\Content\Condition\TableDefinition;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;

class AlwaysCondition extends ConditionHandler implements InputConditionInterface
{
    public function getName(): string
    {
        return "inputAlways";
    }

    /**
     * @return TableDefinition[]
     */
    public function migrate(): array
    {
        return [
            new TableDefinition(
                tableName: "lso_c_always_settings",
                fields: [
                    "id" => ["type" => "integer", "length" => 4, "notnull" => true],
                    "setting_key" => ["type" => "text", "length" => 255, "notnull" => true],
                    "setting_value" => ["type" => "text", "length" => 1000, "notnull" => false],
                ],
                primaryKeys: ["id"],
                hasSequence: true
            ),
            new TableDefinition(
                tableName: "lso_c_always_data",
                fields: [
                    "data_id" => ["type" => "integer", "length" => 4, "notnull" => true],
                    "ref_id" => ["type" => "integer", "length" => 4, "notnull" => true],
                    "content" => ["type" => "clob", "notnull" => false],
                ],
                primaryKeys: ["data_id"],
                hasSequence: true
            )
        ];
    }
}
