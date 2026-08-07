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

namespace ILIAS\LearningSequence\Content\Condition\InputCondition\PointsInputCondition;

use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\PointsOutputCondition\PointsOutputCondition;
use ILIAS\LearningSequence\Content\Condition\TableDefinition;
use ILIAS\UI\Component\Input\Container\Form\Standard as FormStandard;
use ILIAS\UI\Component\Symbol\Glyph\Glyph;

final class PointsInputCondition extends AbstractCondition implements InputConditionInterface
{
    protected const string NAME = 'points_input';
    private const string SETTINGS_TABLE = 'lso_c_points_input';
    private const string POINTS_FIELD = 'points';

    private ?int $points = null;

    /**
     * @inheritDoc
     */
    public static function migrate(): array
    {
        return [
            new TableDefinition(
                tableName: self::SETTINGS_TABLE,
                fields: [
                    'condition_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
                    self::POINTS_FIELD => ['type' => 'integer', 'length' => 4, 'notnull' => true],
                ],
                primaryKeys: ['condition_id']
            )
        ];
    }

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        return $this->getAvailablePoints() >= $this->getPoints();
    }

    /**
     * @inheritDoc
     */
    public function getAdditionalForm(): FormStandard
    {
        $input = $this->ui_factory->input()->field()->numeric(
            $this->lang->txt('points_input'),
            $this->lang->txt('points_input_byline')
        );

        if ($this->condition_id !== null) {
            $input = $input->withValue($this->getPoints());
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
        $points = array_shift($data);
        if (is_array($points) || !is_numeric($points)) {
            throw new \LogicException('Points are invalid.');
        }

        $this->setPoints((int) $points);
    }

    /**
     * Retruns the points required to enter this step.
     *
     * @return int
     */
    public function getPoints(): int
    {
        if ($this->points !== null) {
            return $this->points;
        }

        if ($this->condition_id === null) {
            return 0;
        }

        $res = $this->getDatabase()->queryF(
            'SELECT ' . self::POINTS_FIELD . ' FROM ' . self::SETTINGS_TABLE . ' WHERE condition_id = %s',
            ['integer'],
            [$this->condition_id]
        );
        $row = $this->getDatabase()->fetchAssoc($res);
        if ($row === null || !isset($row[self::POINTS_FIELD])) {
            throw new \LogicException('Points are not stored.');
        }

        $this->points = (int) $row[self::POINTS_FIELD];
        return $this->points;
    }

    /**
     * Sets the points required to enter this step.
     *
     * @param int $points
     */
    public function setPoints(int $points): void
    {
        if ($points < 0) {
            throw new \LogicException('Points must not be negative.');
        }

        $this->points = $points;
    }

    /**
     * @inheritDoc
     */
    protected function createConditionData(int $condition_id): void
    {
        $this->getDatabase()->insert(self::SETTINGS_TABLE, [
            'condition_id' => ['integer', $condition_id],
            self::POINTS_FIELD => ['integer', $this->requirePoints()]
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
                self::POINTS_FIELD => ['integer', $this->requirePoints()]
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
    protected function getGlyphe(): Glyph
    {
        return $this->ui_factory->symbol()->glyph()->settings();
    }

    /**
     * @inheritDoc
     */
    protected function requiresConfiguration(): bool
    {
        return true;
    }

    /**
     * Returns the total points available from previous items in the Learning Sequence.
     *
     * @return int
     */
    private function getAvailablePoints(): int
    {
        $items = $this->getLsoItems();
        $points = 0;

        foreach ($items as $item) {
            if ($item->getRefId() === $this->obj_ref_id) {
                continue;
            }

            $points += $this->getPointsFromItem((int) $item->getRefId());
        }

        return $points;
    }

    /**
     * Returns the points from a previous item in the Learning Sequence.
     *
     * @param int $ref_id
     * @return int
     */
    private function getPointsFromItem(int $ref_id): int
    {
        $condition_id = $this->getOutputConditionIdForItem($ref_id);
        if ($condition_id === null) {
            return 0;
        }

        $condition = new PointsOutputCondition();
        $condition->setLsoRefId($this->lso_ref_id);
        $condition->setObjRefId($ref_id);
        $condition->setConditionId($condition_id);

        try {
            return $condition->check() ? $condition->getPoints() : 0;
        } catch (\LogicException) {
            return 0;
        }
    }

    /**
     * Returns the condition ID for the PointsOutputCondition of a previous item in the Learning Sequence.
     *
     * @param int $ref_id
     * @return int|null
     */
    private function getOutputConditionIdForItem(int $ref_id): ?int
    {
        $condition_name = $this->getIdentifierForClass(PointsOutputCondition::class);
        $res = $this->getDatabase()->queryF(
            'SELECT c.condition_id
                FROM lso_conditions c
                INNER JOIN lso_condition_types t ON c.type_id = t.type_id
                WHERE c.lso_ref_id = %s AND c.obj_ref_id = %s AND t.condition_name = %s',
            ['integer', 'integer', 'text'],
            [$this->lso_ref_id, $ref_id, $condition_name]
        );
        $row = $this->getDatabase()->fetchAssoc($res);
        if ($row === null) {
            return null;
        }

        return (int) $row['condition_id'];
    }

    /**
     * Returns the points that are required to enter this step, or throws an exception if they are not set.
     *
     * @return int
     * @throws \LogicException if the points are not set
     */
    private function requirePoints(): int
    {
        if ($this->points === null) {
            throw new \LogicException('Points are not set.');
        }

        return $this->points;
    }
}
