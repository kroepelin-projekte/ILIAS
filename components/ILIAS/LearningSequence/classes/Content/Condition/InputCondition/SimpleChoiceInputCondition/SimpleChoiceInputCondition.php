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

namespace ILIAS\LearningSequence\Content\Condition\InputCondition\SimpleChoiceInputCondition;

use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\TableDefinition;
use ilLPStatus;

/**
 * Class SimpleChoiceInputCondtion
 */
final class SimpleChoiceInputCondition extends AbstractCondition implements InputConditionInterface
{
    final protected const NAME = "simple_choice";
    private const SETTINGS_TABLE = 'lso_c_simple_choice';
    private ?int $condition_target_ref_id = null;

    /**
     * @inheritDoc
     */
    public static function migrate(): array
    {
        return [
            new TableDefinition(
                tableName: self::SETTINGS_TABLE,
                fields: [
                    "condition_id" => ["type" => "integer", "length" => 4, "notnull" => true],
                    "target_ref_id" => ["type" => "integer", "length" => 4, "notnull" => true],
                ],
                primaryKeys: ["condition_id"]
            )
        ];
    }

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        return ilLPStatus::_hasUserCompleted(
            $this->getConditionTargetRefId(),
            $this->dic->user()->getId()
        );
    }

    /**
     * @inheritDoc
     */
    public function setupSteps(): array
    {
        // TODO: We need some Tree > Expandable to select the condition_target_ref_id
        // Need to check how to use $this->getAdditionalForm() to add a modal with the picker.
        return [];
    }

    public function getConditionTargetRefId(): int
    {
        if ($this->condition_target_ref_id !== null) {
            return $this->condition_target_ref_id;
        }

        $res = $this->getDatabase()->queryF(
            'SELECT target_ref_id FROM ' . self::SETTINGS_TABLE . ' WHERE condition_id = %s',
            ['integer'],
            [$this->resolveConditionId()]
        );
        $row = $this->getDatabase()->fetchAssoc($res);

        if ($row === null) {
            throw new \LogicException('Simple choice target ref id is not stored.');
        }

        $this->condition_target_ref_id = (int) $row['target_ref_id'];
        return $this->condition_target_ref_id;
    }

    public function setConditionTargetRefId(int $condition_target_ref_id): void
    {
        $this->condition_target_ref_id = $condition_target_ref_id;
    }

    protected function createConditionData(int $condition_id): void
    {
        $this->getDatabase()->insert(self::SETTINGS_TABLE, [
            'condition_id' => ['integer', $condition_id],
            'target_ref_id' => ['integer', $this->getConditionTargetRefId()]
        ]);
    }

    protected function editConditionData(int $condition_id): void
    {
        $this->getDatabase()->update(
            self::SETTINGS_TABLE,
            [
                'target_ref_id' => ['integer', $this->getConditionTargetRefId()]
            ],
            [
                'condition_id' => ['integer', $condition_id]
            ]
        );
    }

    protected function deleteConditionData(int $condition_id): void
    {
        $this->getDatabase()->manipulateF(
            'DELETE FROM ' . self::SETTINGS_TABLE . ' WHERE condition_id = %s',
            ['integer'],
            [$condition_id]
        );
    }
}
