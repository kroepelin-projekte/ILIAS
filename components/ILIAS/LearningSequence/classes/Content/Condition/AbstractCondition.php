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

namespace ILIAS\LearningSequence\Content\Condition;

use ilDBInterface;
use ILIAS\UI\Component\Input\Container\Form\Standard as FormStandard;
use ILIAS\UI\Component\Link\Bulky;
use ilObjLearningSequenceContentGUI;
use ilObjLearningSequenceGUI;
use ilRepositoryGUI;

abstract class AbstractCondition
{
    protected const NAME = '';
    protected const SAVE_COMMAND = 'save';
    protected const CONFIGURE_COMMAND = 'configure';

    protected \ilLanguage $lang;
    protected \ILIAS\DI\Container $dic;
    protected ?int $obj_ref_id = null;
    protected ?int $lso_ref_id = null;
    protected ?int $condition_id = null;
    protected \ILIAS\UI\Factory $ui_factory;

    public function __construct()
    {
        global $DIC;
        $this->dic = $DIC;
        $this->lang = $this->dic->language();
        $this->ui_factory = $this->dic->ui()->factory();
    }

    /**
     * Checks if the condition is fulfilled.
     *
     * @return bool
     */
    abstract public function check(): bool;

    /**
     * Returns an array of table definitions for the condition.
     * It has to be implemented in the concrete condition class if additional tables are needed for the condition.
     *
     * @return TableDefinition[]
     */
    public static function migrate(): array
    {
        return [];
    }

    /**
     * Returns the additional form for the condition.
     * Has to be implemented by the child class if an additional form is needed.
     *
     * @return FormStandard
     */
    public function getAdditionalForm(): ?FormStandard
    {
        return null;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return static::NAME;
    }

    /**
     * Returns an array of steps to configure the condition.
     * Has to be implemented by the child class if additional steps are needed.
     *
     * @return Bulky[]
     */
    public function setupSteps(): array
    {
        $this->assertContextSet();
        return [$this->buildStep()];
    }

    /**
     * @return int|null
     */
    public function getObjRefId(): ?int
    {
        return $this->obj_ref_id;
    }

    /**
     * @param int|null $obj_ref_id
     */
    public function setObjRefId(?int $obj_ref_id): void
    {
        $this->obj_ref_id = $obj_ref_id;
    }

    /**
     * @return int|null
     */
    public function getLsoRefId(): ?int
    {
        return $this->lso_ref_id;
    }

    /**
     * @param int|null $lso_ref_id
     */
    public function setLsoRefId(?int $lso_ref_id): void
    {
        $this->lso_ref_id = $lso_ref_id;
    }

    /**
     * @return int|null
     */
    public function getConditionId(): ?int
    {
        return $this->condition_id;
    }

    /**
     * @param int|null $condition_id
     */
    public function setConditionId(?int $condition_id): void
    {
        $this->condition_id = $condition_id;
    }

    /**
     * Saves the condition to the DB
     */
    public function create(): void
    {
        $this->assertContextSet();
        $this->assertConditionDataHookImplemented('createConditionData');

        $type_id = $this->getTypeId();
        $existing_id = $this->findConditionIdByContextAndType($type_id);
        if ($existing_id !== null) {
            throw new \LogicException('Condition already exists for this item and type.');
        }

        $db = $this->getDatabase();
        $condition_id = $db->nextId('lso_conditions');
        $db->insert('lso_conditions', [
            'condition_id' => ['integer', $condition_id],
            'lso_ref_id' => ['integer', $this->lso_ref_id],
            'obj_ref_id' => ['integer', $this->obj_ref_id],
            'type_id' => ['integer', $type_id]
        ]);

        $this->condition_id = $condition_id;
        $this->createConditionData($condition_id);
    }

    /**
     * Edits the condition.
     */
    public function edit(): void
    {
        $this->assertContextSet();
        $this->assertConditionDataHookImplemented('editConditionData');

        $condition_id = $this->condition_id ?? $this->resolveConditionId();
        $type_id = $this->getTypeId();

        $this->getDatabase()->update(
            'lso_conditions',
            [
                'lso_ref_id' => ['integer', $this->lso_ref_id],
                'obj_ref_id' => ['integer', $this->obj_ref_id],
                'type_id' => ['integer', $type_id]
            ],
            [
                'condition_id' => ['integer', $condition_id]
            ]
        );

        $this->condition_id = $condition_id;
        $this->editConditionData($condition_id);
    }

    /**
     * Deletes the condition from the database.
     */
    public function delete(): void
    {
        $this->assertConditionDataHookImplemented('deleteConditionData');
        $condition_id = $this->condition_id ?? $this->resolveConditionId();

        $this->deleteConditionData($condition_id);

        $this->getDatabase()->manipulateF(
            'DELETE FROM lso_conditions WHERE condition_id = %s',
            ['integer'],
            [$condition_id]
        );

        $this->condition_id = null;
    }

    /**
     * Builds a URL for the given command.
     *
     * @param string $command
     * @return \ILIAS\Data\URI
     */
    protected function buildUrl(string $command): \ILIAS\Data\URI
    {
        $url = $this->dic->ctrl()->getLinkTargetByClass(
            [
                ilRepositoryGUI::class,
                ilObjLearningSequenceGUI::class,
                ilObjLearningSequenceContentGUI::class,
                \ilObjLearningSequenceConditionsGUI::class
            ],
            $command
        );
        return new \ILIAS\Data\URI(ILIAS_HTTP_PATH . '/' . $url);
    }

    /**
     * Asserts that the context (lso_ref_id and obj_ref_id) is set.
     *
     * @throws \LogicException if the context is not set
     */
    protected function assertContextSet(): void
    {
        if ($this->lso_ref_id === null || $this->obj_ref_id === null) {
            throw new \LogicException('Condition context is incomplete.');
        }
    }

    /**
     * Hook for condition-specific payload persistence during create().
     * Override this method if migrate() contributes additional table definitions.
     */
    protected function createConditionData(int $condition_id): void
    {
    }

    /**
     * Hook for condition-specific payload persistence during edit().
     * Override this method if migrate() contributes additional table definitions.
     */
    protected function editConditionData(int $condition_id): void
    {
    }

    /**
     * Hook for condition-specific payload cleanup during delete().
     * Override this method if migrate() contributes additional table definitions.
     */
    protected function deleteConditionData(int $condition_id): void
    {
    }

    /**
     * Ensures condition-specific payload hooks are implemented if migrate() defines extra tables.
     */
    protected function assertConditionDataHookImplemented(string $hook_method): void
    {
        if (count(static::migrate()) === 0) {
            return;
        }

        $method = new \ReflectionMethod(static::class, $hook_method);
        if ($method->getDeclaringClass()->getName() === self::class) {
            throw new \LogicException(
                sprintf(
                    '%s defines additional migration tables but does not override %s().',
                    static::class,
                    $hook_method
                )
            );
        }
    }

    /**
     * Returns the database interface.
     *
     * @return ilDBInterface
     */
    protected function getDatabase(): ilDBInterface
    {
        return $this->dic->database();
    }

    /**
     * Returns the type ID for this condition.
     *
     * @return int
     * @throws \LogicException if the condition type is not registered
     */
    protected function getTypeId(): int
    {
        $res = $this->getDatabase()->queryF(
            'SELECT type_id FROM lso_condition_types WHERE condition_name = %s',
            ['text'],
            [$this->getIdentifierForClass(static::class)]
        );
        $row = $this->getDatabase()->fetchAssoc($res);

        if ($row === null) {
            throw new \LogicException('Condition type is not registered.');
        }

        return (int) $row['type_id'];
    }

    /**
     * Resolves the condition ID for this condition based on the context and type.
     *
     * @return int
     * @throws \LogicException if the condition does not exist or is ambiguous
     */
    protected function resolveConditionId(): int
    {
        if ($this->condition_id !== null) {
            return $this->condition_id;
        }

        $this->assertContextSet();
        $type_id = $this->getTypeId();
        $condition_id = $this->findConditionIdByContextAndType($type_id);

        if ($condition_id === null) {
            throw new \LogicException('Condition does not exist.');
        }

        $this->condition_id = $condition_id;
        return $condition_id;
    }

    /**
     * Finds the condition ID based on the context (lso_ref_id, obj_ref_id) and type.
     *
     * @param int $type_id
     * @return int|null
     * @throws \LogicException if the lookup is ambiguous
     */
    protected function findConditionIdByContextAndType(int $type_id): ?int
    {
        $res = $this->getDatabase()->queryF(
            'SELECT condition_id FROM lso_conditions WHERE lso_ref_id = %s AND obj_ref_id = %s AND type_id = %s',
            ['integer', 'integer', 'integer'],
            [$this->lso_ref_id, $this->obj_ref_id, $type_id]
        );

        $row = $this->getDatabase()->fetchAssoc($res);
        if ($row === null) {
            return null;
        }

        if ($this->getDatabase()->fetchAssoc($res) !== null) {
            throw new \LogicException('Condition lookup is ambiguous.');
        }

        return (int) $row['condition_id'];
    }

    /**
     * Extracts the identifier for the condition type from the class name.
     *
     * @param string $class
     * @return string
     */
    protected function getIdentifierForClass(string $class): string
    {
        $parts = explode('\\', $class);
        $short_name = end($parts);
        return preg_replace('/Condition$/', '', $short_name);
    }

    /**
     * Determines the command to use for the next step based on whether configuration is required.
     *
     * @return string
     */
    protected function getStepCommand(): string
    {
        return $this->requiresConfiguration() ? self::CONFIGURE_COMMAND : self::SAVE_COMMAND;
    }

    /**
     * Builds a bulky link for the next step in the condition setup.
     *
     * @param array $additional_parameters, e.g. subtypes
     * @param string|null $label
     * @param string|null $command, e.g. 'save' or 'configure'
     * @param string|null $icon_abbreviation
     * @return Bulky
     */
    protected function buildStep(
        array $additional_parameters = [],
        ?string $label = null,
        ?string $command = null,
    ): Bulky {
        $this->dic->ctrl()->setParameterByClass(
            \ilObjLearningSequenceConditionsGUI::class,
            'type_id',
            $this->getTypeId()
        );
        $this->dic->ctrl()->setParameterByClass(
            \ilObjLearningSequenceConditionsGUI::class,
            'item_ref_id',
            (string) $this->obj_ref_id
        );
        $this->dic->ctrl()->setParameterByClass(
            \ilObjLearningSequenceConditionsGUI::class,
            'ref_id',
            (string) $this->lso_ref_id
        );

        foreach ($additional_parameters as $name => $value) {
            $this->dic->ctrl()->setParameterByClass(
                \ilObjLearningSequenceConditionsGUI::class,
                (string) $name,
                (string) $value
            );
        }

        $uri = $this->buildUrl($command ?? $this->getStepCommand());
        $this->dic->ctrl()->clearParametersByClass(\ilObjLearningSequenceConditionsGUI::class);

        return $this->ui_factory->link()->bulky(
            $this->getGlyphe(),
            $label ?? (string) $this->getName(),
            $uri
        );
    }

    /**
     * Determines whether the condition requires additional configuration.
     * Override this method in child classes if configuration is needed.
     *
     * @return bool
     */
    protected function requiresConfiguration(): bool
    {
        return false;
    }

    /**
     * Returns the glyphe for the condition.
     * Override this method in child classes to provide a specific glyph.
     *
     * @return \ILIAS\UI\Component\Symbol\Glyph\Glyph
     */
    protected function getGlyphe(): \ILIAS\UI\Component\Symbol\Glyph\Glyph
    {
        return $this->ui_factory->symbol()->glyph()->apply();
    }

    // TODO: Prüfen, wohin wir diese beiden Helper auslagern können
    /**
     * Returns the learning sequence object associated with this condition.
     *
     * @return \ilObjLearningSequence
     * @throws \LogicException if the LSO ref id is not set or the object is not an ilObjLearningSequence
     */
    protected function getLso(): \ilObjLearningSequence
    {
        $lso_ref_id = $this->getLsoRefId();
        if ($lso_ref_id === null) {
            throw new \LogicException('LSO ref id is not set.');
        }

        /** @var \ilObjLearningSequence $object */
        $object = \ilObjectFactory::getInstanceByRefId($lso_ref_id);
        if (!$object instanceof \ilObjLearningSequence) {
            throw new \LogicException('Object is not an ilObjLearningSequence.');
        }

        return $object;
    }

    /**
     * Returns the items of the learning sequence associated with this condition.
     *
     * @return array
     */
    protected function getLsoItems(): array
    {
        return $this->getLso()->getLSItems();
    }
}
