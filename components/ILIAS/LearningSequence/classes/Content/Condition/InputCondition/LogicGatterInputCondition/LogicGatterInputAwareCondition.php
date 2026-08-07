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
use ilException;
use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\ConditionFactory;
use ILIAS\LearningSequence\Content\Condition\SubtypeAwareInterface;
use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover;
use ILIAS\LearningSequence\Content\Condition\LSOObjectPicker;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\TableDefinition;
use ILIAS\UI\Component\Input\Container\Form\Standard as FormStandard;
use ILIAS\UI\Component\Link\Bulky;
use ILIAS\UI\Component\Symbol\Glyph\Glyph;
use ILIAS\UI\Component\Symbol\Symbol;
use ReflectionException;

class LogicGatterInputAwareCondition extends AbstractCondition implements InputConditionInterface, SubtypeAwareInterface
{
    final protected const string NAME = "logic_gatter";
    private const string SETTINGS_TABLE = 'lso_c_logic_gatter_input';

    private const string SUBTYPE_AND = 'AND';
    private const string SUBTYPE_OR = 'OR';
    private const string SUBTYPE_NOT = 'NOT';
    private string $items = '';
    private ilObjLearningSequenceConditionDiscover $discover;
    private ConditionFactory $condition_factory;

    public function __construct(?int $condition_id = null)
    {
        parent::__construct($condition_id);

        $this->discover = new ilObjLearningSequenceConditionDiscover();
        $this->condition_factory = new ConditionFactory(
            $this->discover,
            $this->dic->database(),
        );
    }

    /**
     * @return void
     */
    public function read(): void
    {
        parent::read();

        $db = $this->dic->database();
        $query = $db->query(
            "SELECT items FROM " . self::SETTINGS_TABLE . " WHERE condition_id = " . $db->quote($this->condition_id, 'integer')
        );
        if ($row = $db->fetchAssoc($query)) {
            $this->items = $row['items'];
        }
    }

    /**
     * @return string
     */
    public function getItems(): string
    {
        return $this->items;
    }

    /**
     * @param string $items
     * @return void
     */
    public function setItems(string $items): void
    {
        $this->items = $items;
    }

    /**
     * @return array
     */
    private function getItemsAsArray(): array
    {
        return array_filter(explode(', ', $this->items));
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
                    'items' => ['type' => 'text', 'length' => 4000, 'notnull' => true],
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
            $this->ui_factory->menu()->sub($this->lang->txt($this->getName()), [
                $this->buildSubtypeStep(self::SUBTYPE_AND),
                $this->buildSubtypeStep(self::SUBTYPE_OR),
                $this->buildSubtypeStep(self::SUBTYPE_NOT),
            ])
        ];
    }

    /**
     * @return bool
     * @throws ReflectionException
     * @throws ilException
     */
    public function check(): bool
    {
        return match ($this->getSubtype()) {
            self::SUBTYPE_AND => $this->areAllItemsCompleted(),
            self::SUBTYPE_OR => $this->isAnyItemCompleted(),
            self::SUBTYPE_NOT => $this->areNoItemsCompleted(),
            default => throw new \LogicException($this->lang->txt('lso_exception_unknown_logic_gatter_subtype'))
        };
    }

    /**
     * Check if at least one output condition of the item is fulfilled.
     *
     * @throws ReflectionException
     * @throws ilException
     */
    private function isItemCompleted(int $item_ref_id): bool
    {
        $conditions_of_item = $this->discover->getAllConditionIdsForItem($item_ref_id);

        $conditions = array_map(
            fn($condition_id) => $this->condition_factory->getConditionInstanceById($condition_id),
            $conditions_of_item
        );

        $output_conditions = array_filter(
            $conditions,
            fn($condition) => $condition instanceof OutputConditionInterface
        );

        return array_any(
            $output_conditions,
            fn($condition) => $condition->check()
        );
    }

    /**
     * @return bool
     * @throws ReflectionException
     * @throws ilException
     */
    private function areAllItemsCompleted(): bool
    {
        $items = $this->getItemsAsArray();

        if (empty($items)) {
            return true;
        }

        return array_all(
            $items,
            fn($item_ref_id) => $this->isItemCompleted($item_ref_id)
        );
    }

    /**
     * @return bool
     * @throws ReflectionException
     * @throws ilException
     */
    private function isAnyItemCompleted(): bool
    {
        return array_any(
            $this->getItemsAsArray(),
            fn($item_ref_id) => $this->isItemCompleted($item_ref_id)
        );
    }

    /**
     * @return bool
     * @throws ReflectionException
     * @throws ilException
     */
    private function areNoItemsCompleted(): bool
    {
        return !$this->isAnyItemCompleted();
    }

    /**
     * @param string $subtype
     * @return string
     */
    public function getSubtypeLabel(string $subtype): string
    {
        return match ($subtype) {
            self::SUBTYPE_AND => $this->lang->txt('logic_gatter_and'),
            self::SUBTYPE_OR => $this->lang->txt('logic_gatter_or'),
            self::SUBTYPE_NOT => $this->lang->txt('logic_gatter_not'),
            default => throw new \LogicException($this->lang->txt('lso_exception_unknown_logic_gatter_subtype'))
        };
    }

    /**
     * @return string[]
     */
    public function getSupportedSubtypes(): array
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
            throw new \LogicException($this->lang->txt('lso_exception_logic_gatter_condition_id_not_set'));
        }

        $res = $this->getDatabase()->queryF(
            'SELECT subtype FROM ' . self::SETTINGS_TABLE . ' WHERE condition_id = %s',
            ['integer'],
            [$this->condition_id]
        );
        $row = $this->getDatabase()->fetchAssoc($res);

        if ($row === null || !is_string($row['subtype'])) {
            throw new \LogicException($this->lang->txt('lso_exception_logic_gatter_subtype_not_stored'));
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
            $this->lang->txt('lso_condition_simple_multi_target'),
            true,
        )->withByline($this->lang->txt($this->getSubtype() . '_byline'));

        if ($this->condition_id !== null) {
            $input = $input->withValue($this->getItemsAsArray());
        }

        return $this->ui_factory->input()->container()->form()->standard(
            $this->buildUrl(self::CREATE_COMMAND, true)->__toString(),
            [ $input ]
        );
    }

    /**
     * @throws ilCtrlException
     */
    public function buildSubtypeStep(string $subtype): Bulky
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
        $items_string = implode(', ', array_filter($data[0] ?? []));
        $this->setItems($items_string);
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
            'items' => ['text', $this->getItems()],
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
            'items' => ['text', $this->getItems()],
        ], [
            'condition_id' => ['integer', $condition_id],
        ]);
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
     * @return Glyph|Symbol
     */
    protected function getGlyphe(): Glyph|Symbol
    {
        return $this->ui_factory->symbol()->icon()->custom('', '');
    }
}
