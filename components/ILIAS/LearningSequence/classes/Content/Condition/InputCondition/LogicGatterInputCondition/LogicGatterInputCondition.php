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

namespace ILIAS\LearningSequence\Content\Condition\InputCondition;

use ilCtrlException;
use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\LSOObjectPicker;
use ILIAS\LearningSequence\Content\Condition\TableDefinition;
use ILIAS\UI\Component\Input\Container\Form\Standard as FormStandard;
use ILIAS\UI\Component\Link\Bulky;
use ilObjLearningSequenceConditionConfigurationGUI;

class LogicGatterInputCondition extends AbstractCondition implements InputConditionInterface
{
    final protected const string NAME = "logic_gatter";
    private const string SETTINGS_TABLE = 'lso_c_logic_gatter_input';

    private const string SUBTYPE_AND = 'AND';
    private const string SUBTYPE_OR = 'OR';
    private const string SUBTYPE_NOT = 'NOT';
    private string $objects = '';

    public function read(): void
    {
        parent::read();

        $db = $this->dic->database();
        $query = $db->query(
            "SELECT objects FROM " . self::SETTINGS_TABLE . " WHERE condition_id = " . $db->quote($this->condition_id, 'integer')
        );
        if ($row = $db->fetchAssoc($query)) {
            $this->objects = $row['objects'];
        }
    }

    public function getObjects(): string
    {
        return $this->objects;
    }

    public function setObjects(string $objects): void
    {
        $this->objects = $objects;
    }

    /**
     * @return TableDefinition[]
     */
    public static function migrate(): array
    {
        return [
            new TableDefinition(
                tableName: self::SETTINGS_TABLE,
                fields: [
                    'condition_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
                    'subtype' => ['type' => 'text', 'length' => 32, 'notnull' => true],
                    'objects' => ['type' => 'text', 'length' => 4000, 'notnull' => true],
                ],
                primaryKeys: ['condition_id']
            )
        ];
    }

    /**
     * @return array|Bulky[]
     * @throws ilCtrlException
     */
    public function setupSteps(): array
    {
        $this->assertContextSet();

        return [
            $this->ui_factory->menu()->sub($this->getName(), [
                $this->buildSubtypeStep(self::SUBTYPE_AND),
                $this->buildSubtypeStep(self::SUBTYPE_OR),
                $this->buildSubtypeStep(self::SUBTYPE_NOT),
            ])
        ];
    }

    /**
     * @return bool
     */
    public function check(): bool
    {
        return match ($this->getSubtype()) {
            self::SUBTYPE_AND => $this->areAllItemsCompleted(),
            self::SUBTYPE_OR => $this->isAnyItemCompleted(),
            self::SUBTYPE_NOT => $this->areNoItemsCompleted(),
            default => throw new \LogicException('Unknown logic gatter subtype.')
        };
    }

    /**
     * @return bool
     */
    private function areAllItemsCompleted(): bool
    {
        return array_all($this->getLsoItems(), fn($lsoItem) => $lsoItem->isCompleted());
    }

    /**
     * @return bool
     */
    private function isAnyItemCompleted(): bool
    {
        return array_any($this->getLsoItems(), fn($lsoItem) => $lsoItem->isCompleted());
    }

    /**
     * @return bool
     */
    private function areNoItemsCompleted(): bool
    {
        return array_all($this->getLsoItems(), fn($lsoItem) => !$lsoItem->isCompleted());
    }

    /**
     * @param string $subtype
     * @return string
     */
    private function getSubtypeLabel(string $subtype): string
    {
        return match ($subtype) {
            self::SUBTYPE_AND => 'logic_gatter_and',
            self::SUBTYPE_OR => 'logic_gatter_or',
            self::SUBTYPE_NOT => 'logic_gatter_not',
            default => throw new \LogicException('Unknown logic gatter subtype.')
        };
    }

    /**
     * @return string[]
     */
    protected function getSupportedSubtypes(): array
    {
        return [
            self::SUBTYPE_AND,
            self::SUBTYPE_OR,
            self::SUBTYPE_NOT,
        ];
    }

    /**
     * @return string
     */
    public function getSubtype(): string
    {
        if ($this->subtype !== null) {
            return $this->subtype;
        }

        if ($this->condition_id === null) {
            throw new \LogicException('Logic gatter condition_id is not set.');
        }

        $res = $this->getDatabase()->queryF(
            'SELECT subtype FROM ' . self::SETTINGS_TABLE . ' WHERE condition_id = %s',
            ['integer'],
            [$this->condition_id]
        );
        $row = $this->getDatabase()->fetchAssoc($res);

        if ($row === null || !is_string($row['subtype'])) {
            throw new \LogicException('Logic gatter subtype is not stored.');
        }

        $this->setSubtype($row['subtype']);
        return (string) $this->subtype;
    }

    /**
     * @return bool
     */
    protected function requiresConfiguration(): bool
    {
        return true;
    }

    /**
     * @throws ilCtrlException
     */
    public function getAdditionalForm(): ?FormStandard
    {
        $input = new LSOObjectPicker((int) $this->lso_ref_id)->getPicker(
            $this->lang->txt('lso_condition_simple_choice_target'),
            true,
        );

        if ($this->condition_id !== null) {

            $objects_array = array_filter(
                explode(', ', $this->getObjects())
            );

            $input = $input->withValue($objects_array);
        }

        return $this->ui_factory->input()->container()->form()->standard(
            $this->buildUrl(self::CREATE_COMMAND, true)->__toString(),
            [ $input ]
        );
    }

    /**
     * @throws ilCtrlException
     */
    private function buildSubtypeStep(string $subtype): Bulky
    {
        return $this->buildStep(
            ['subtype' => $subtype],
            $this->getSubtypeLabel($subtype),
            self::CONFIGURE_COMMAND
        );
    }

    /**
     * @param array $data
     */
    public function applyAdditionalFormData(array $data): void
    {
        $objects_string = implode(', ', array_filter($data[0] ?? []));
        $this->setObjects($objects_string);
    }

    /**
     * @param int $condition_id
     * @return void
     */
    protected function createConditionData(int $condition_id): void
    {
        $this->getDatabase()->insert(self::SETTINGS_TABLE, [
            'condition_id' => ['integer', $condition_id],
            'subtype' => ['text', $this->getSubtype()],
            'objects' => ['text', $this->getObjects()],
        ]);
    }

    /**
     * @param int $condition_id
     * @return void
     */
    protected function editConditionData(int $condition_id): void
    {
        $this->getDatabase()->update(self::SETTINGS_TABLE, [
            'subtype' => ['text', $this->getSubtype()],
            'objects' => ['text', $this->getObjects()],
        ], [
            'condition_id' => ['integer', $condition_id],
        ]);
    }
}
