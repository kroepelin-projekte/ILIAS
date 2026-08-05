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
use ILIAS\LearningSequence\Content\Condition\LSOObjectPicker;
use ILIAS\LearningSequence\Content\Condition\TableDefinition;
use ILIAS\UI\Component\Input\Container\Form\Standard as FormStandard;
use ilLPStatus;

/**
 * Class SimpleChoiceInputCondtion
 */
final class SimpleChoiceInputCondition extends AbstractCondition implements InputConditionInterface
{
    final protected const string NAME = "simple_choice";
    private const string SETTINGS_TABLE = 'lso_c_simple_choice';
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
    protected function requiresConfiguration(): bool
    {
        return true;
    }

    /**
     * Returns a picker to select the target ref id for the simple choice input condition.
     *
     * @return FormStandard
     */
    public function getAdditionalForm(): FormStandard
    {
        $input = (new LSOObjectPicker((int) $this->lso_ref_id))->getPicker(
            $this->lang->txt('lso_condition_simple_choice_target'),
            false,
        )
            ->withRequired(false)
            ->withAdditionalTransformation(
                $this->dic->refinery()->custom()->constraint(
                    static fn($value): bool => is_array($value)
                        && count($value) === 1
                        && isset($value[0])
                        && $value[0] !== '',
                    'Simple choice target ref id is invalid.'
                )
            );

        if ($this->condition_id !== null) {
            $input = $input->withValue((string) $this->getConditionTargetRefId());
        }

        return $this->ui_factory->input()->container()->form()->standard(
            $this->buildUrl(self::CREATE_COMMAND, true)->__toString(),
            [ $input ]
        );
    }

    /**
     * @param array<mixed> $data
     */
    public function applyAdditionalFormData(array $data): void
    {
        $target_ref_ids = array_shift($data);
        if (
            !is_array($target_ref_ids)
            || count($target_ref_ids) !== 1
            || !isset($target_ref_ids[0])
            || $target_ref_ids[0] === ''
        ) {
            throw new \LogicException('Simple choice target ref id is invalid.');
        }

        $this->setConditionTargetRefId((int) $target_ref_ids[0]);
    }

    /**
     * Returns the target ref id for the simple choice input condition.
     *
     * @return int
     */
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
        /** @var string[]|null $row */
        $row = $this->getDatabase()->fetchAssoc($res);

        if ($row === null) {
            throw new \LogicException('Simple choice target ref id is not stored.');
        }

        $this->condition_target_ref_id = (int) $row['target_ref_id'];
        return $this->condition_target_ref_id;
    }

    /**
     * Sets the target ref id for the simple choice input condition.
     *
     * @param int $condition_target_ref_id
     */
    public function setConditionTargetRefId(int $condition_target_ref_id): void
    {
        $this->condition_target_ref_id = $condition_target_ref_id;
    }

    /**
     * @inheritDoc
     */
    protected function createConditionData(int $condition_id): void
    {
        $this->getDatabase()->insert(self::SETTINGS_TABLE, [
            'condition_id' => ['integer', $condition_id],
            'target_ref_id' => ['integer', $this->getConditionTargetRefId()]
        ]);
    }

    /**
     * @inheritDoc
     */
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

    /**
     * @inheritDoc
     */
    protected function deleteConditionData(int $condition_id): void
    {
        $this->getDatabase()->manipulateF(
            'DELETE FROM ' . self::SETTINGS_TABLE . ' WHERE condition_id = %s',
            ['integer'],
            [$condition_id]
        );
    }

    /**
     * @inheritDoc
     */
    protected function getGlyphe(): \ILIAS\UI\Component\Symbol\Glyph\Glyph
    {
        return $this->ui_factory->symbol()->glyph()->settings();
    }
}
