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

namespace ILIAS\LearningSequence\Content\Condition\OutputCondition\AlwaysOutputCondition;

use ILIAS\LearningSequence\Content\Condition\AbstractLeafCondition;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\TableDefinition;

/**
 * Class AlwaysOutputCondition
 *
 * Bei Always Condition beachten, dass es keine Option gibt eine andere OutputCondition zu wählen. ALWAYS!!!
 */
final class AlwaysOutputCondition extends AbstractLeafCondition implements OutputConditionInterface
{
    final protected const string NAME = "always";

    /**
     * @inheritDoc
     */
    public static function migrate(): array
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

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function getStepIconAbbreviation(): string
    {
        return '+';
    }

    public function getName(): ?string
    {
        return self::NAME;
    }
}
