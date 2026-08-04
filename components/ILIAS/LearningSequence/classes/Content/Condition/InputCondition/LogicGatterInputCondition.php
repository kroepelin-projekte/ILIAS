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

class LogicGatterInputCondition extends AbstractCondition implements InputConditionInterface
{
    final protected const string NAME = "logic_gatter";
    private const string SETTINGS_TABLE = 'lso_c_logic_gatter_input';

    private const string SUBTYPE_AND = 'AND';
    private const string SUBTYPE_OR = 'OR';
    private const string SUBTYPE_NOT = 'NOT';
    private ?string $subtype = null;

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
                ],
                primaryKeys: ['condition_id']
            )
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
     * @return string[]
     */
    private function getSupportedSubtypes(): array
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
     * @param string $subtype
     * @return void
     */
    public function setSubtype(string $subtype): void
    {
        if (!in_array($subtype, $this->getSupportedSubtypes(), true)) {
            throw new \LogicException('Unsupported learning progress subtype.');
        }

        $this->subtype = $subtype;
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
        $input = (new LSOObjectPicker((int) $this->lso_ref_id))->getPicker(
            $this->lang->txt('lso_condition_simple_choice_target'),
            true,
        );
        return $this->ui_factory->input()->container()->form()->standard(
            $this->buildUrl(self::CREATE_COMMAND)->__toString(),
            [ $input ]
        );
    }
}