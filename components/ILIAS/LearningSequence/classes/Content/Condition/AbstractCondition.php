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

use ilCtrlException;
use ilDBInterface;
use ILIAS\Data\URI;
use ILIAS\DI\Container;
use ILIAS\UI\Component\Input\Container\Form\Standard as FormStandard;
use ILIAS\UI\Component\Link\Bulky;
use ILIAS\UI\Component\Symbol\Glyph\Glyph;
use ILIAS\UI\Component\Symbol\Symbol;
use ILIAS\UI\Factory;
use ilLanguage;
use ilObjectFactory;
use ilObjLearningSequence;
use ilObjLearningSequenceConditionsGUI;
use ilObjLearningSequenceContentGUI;
use ilObjLearningSequenceGUI;
use ilRepositoryGUI;
use LogicException;
use ReflectionException;
use ReflectionMethod;

abstract class AbstractCondition
{
    protected const string NAME = '';
    protected const string CREATE_COMMAND = 'createCondition';
    protected const string CONFIGURE_COMMAND = 'configure';

    protected ilLanguage $lang;
    protected Container $dic;
    protected ?int $obj_ref_id = null;
    protected ?int $lso_ref_id = null;
    protected ?int $condition_id = null;
    protected ?int $type_id = null;
    protected ?string $subtype = null;
    protected Factory $ui_factory;
    /**
     * Ref-id mappings (old → new) passed in from the importer.
     * Used by importPayload() to rewrite ref_id values in payload rows.
     *
     * @var array<string, string>
     */
    protected array $import_mapping = [];

    public function __construct(?int $condition_id = null)
    {
        global $DIC;
        $this->dic = $DIC;
        $this->lang = $this->dic->language();
        $this->ui_factory = $this->dic->ui()->factory();
        if ($condition_id) {
            $this->setConditionId($condition_id);
            $this->read();
        }
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
     * @return FormStandard|null
     */
    public function getAdditionalForm(): ?FormStandard
    {
        return null;
    }

    /**
     * Returns additional condition-specific information for table overviews.
     */
    public function getAdditionalDisplayInformation(): string
    {
        return '';
    }

    /**
     * Returns condition-specific object titles for table overviews.
     *
     * @return string[]
     */
    public function getAdditionalDisplayObjectTitles(): array
    {
        return [];
    }

    /**
     * Applies validated additional form data to the condition before create()/edit().
     *
     * @param array $data
     */
    public function applyAdditionalFormData(array $data): void
    {
        // If a condition exposes an additional form it MUST implement this hook
        if (self::migrate()) {
            $method = new \ReflectionMethod(static::class, 'applyAdditionalFormData');
            if ($method->getDeclaringClass()->getName() === self::class) {
                throw new \LogicException(
                    sprintf(
                        $this->lang->txt('lso_exception_additional_form_no_override'),
                        static::class
                    )
                );
            }
        }
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return static::NAME;
    }

    /**
     * @param string $subtype
     */
    public function setSubtype(string $subtype): void
    {
        if (!$this instanceof SubtypeAwareInterface) {
            throw new LogicException(sprintf($this->lang->txt('lso_exception_no_subtype_support'), static::class));
        }

        if (!in_array($subtype, $this->getSupportedSubtypes(), true)) {
            throw new LogicException(sprintf($this->lang->txt('lso_exception_unsupported_subtype'), $subtype));
        }

        $this->subtype = $subtype;
    }

    /**
     * Returns an array of steps to configure the condition.
     * Has to be implemented by the child class if additional steps are needed.
     *
     * @return Bulky[]
     * @throws ilCtrlException
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
     * @param int|null $type_id
     */
    public function setTypeId(?int $type_id): void
    {
        $this->type_id = $type_id;
    }

    /**
     * @return int|null
     */
    public function getTypeId(): ?int
    {
        return $this->type_id ?: $this->getTypeIdFromDb();
    }

    /**
     * Saves the condition to the DB
     * @param bool $is_import If true, skips createConditionData() (data will be filled by importPayload())
     * @throws ReflectionException
     */
    public function create(bool $is_import = false): void
    {
        $this->assertContextSet();
        $this->assertConditionDataHookImplemented('createConditionData');
        $type_id = $this->getTypeId();

        if (
            !$this->allowMultipleConditionsOfSameType()
            && $this->conditionTypeExistsInContext($type_id)
        ) {
            throw new LogicException($this->lang->txt('lso_exception_condition_exists'));
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
        
        // Only call createConditionData if this is NOT an import
        // (during import, data will be filled by importPayload())
        if (!$is_import) {
            $this->createConditionData($condition_id);
        }
    }

    /**
     * Edits the condition.
     * @throws ReflectionException
     */
    public function edit(): void
    {
        $this->assertContextSet();
        $this->assertConditionDataHookImplemented('editConditionData');
        $type_id = $this->getTypeId();

        $this->getDatabase()->update(
            'lso_conditions',
            [
                'lso_ref_id' => ['integer', $this->lso_ref_id],
                'obj_ref_id' => ['integer', $this->obj_ref_id],
                'type_id' => ['integer', $type_id]
            ],
            [
                'condition_id' => ['integer', $this->condition_id]
            ]
        );

        $this->editConditionData($this->condition_id);
    }

    /**
     * Deletes the condition from the database.
     * @throws ReflectionException
     */
    public function delete(): void
    {
        $this->assertConditionDataHookImplemented('deleteConditionData');

        $this->deleteConditionData($this->condition_id);

        $this->getDatabase()->manipulateF(
            'DELETE FROM lso_conditions WHERE condition_id = %s',
            ['integer'],
            [$this->condition_id]
        );

        $this->condition_id = null;
    }

    /**
     * Builds a URL for the given command.
     *
     * @param string $command
     * @param bool $with_configuration_gui
     * @return URI
     * @throws ilCtrlException
     */
    protected function buildUrl(string $command, bool $with_configuration_gui = false): URI
    {
        $this->dic->ctrl()->setParameterByClass(
            ilObjLearningSequenceConditionsGUI::class,
            'type_id',
            $this->getTypeId()
        );

        $route = [
            ilRepositoryGUI::class,
            ilObjLearningSequenceGUI::class,
            ilObjLearningSequenceContentGUI::class,
            ilObjLearningSequenceConditionsGUI::class
        ];

        if ($command === self::CONFIGURE_COMMAND || $with_configuration_gui) {
            if ($this->condition_id !== null) {
                $this->dic->ctrl()->setParameterByClass(
                    \ilObjLearningSequenceConditionConfigurationGUI::class,
                    'condition_id',
                    (string) $this->condition_id
                );
            }
            $route[] = \ilObjLearningSequenceConditionConfigurationGUI::class;
        }

        $url = $this->dic->ctrl()->getLinkTargetByClass(
            $route,
            $command
        );
        return new URI(ILIAS_HTTP_PATH . '/' . $url);
    }

    /**
     * Asserts that the context (lso_ref_id and obj_ref_id) is set.
     *
     * @throws LogicException if the context is not set
     */
    protected function assertContextSet(): void
    {
        if ($this->lso_ref_id === null || $this->obj_ref_id === null) {
            throw new LogicException($this->lang->txt('lso_exception_context_incomplete'));
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
     * @throws ReflectionException
     */
    protected function assertConditionDataHookImplemented(string $hook_method): void
    {
        if (count(static::migrate()) === 0) {
            return;
        }

        $method = new ReflectionMethod(static::class, $hook_method);
        if ($method->getDeclaringClass()->getName() === self::class) {
            throw new LogicException(
                sprintf(
                    $this->lang->txt('lso_exception_migration_no_hook_override'),
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

    protected function getObjectTitleByRefId(int $ref_id): string
    {
        $obj_id = \ilObject::_lookupObjId($ref_id);
        if ($obj_id > 0) {
            return \ilObject::_lookupTitle($obj_id);
        }

        return sprintf('Ref-ID %d', $ref_id);
    }

    /**
     * Returns the type ID for this condition.
     *
     * @return int
     * @throws LogicException if the condition type is not registered
     */
    protected function getTypeIdFromDb(): int
    {
        $res = $this->getDatabase()->queryF(
            'SELECT type_id FROM lso_condition_types WHERE condition_name = %s',
            ['text'],
            [$this->getIdentifierForClass(static::class)]
        );
        $row = $this->getDatabase()->fetchAssoc($res);

        if ($row === null) {
            throw new LogicException($this->lang->txt('lso_exception_type_not_registered'));
        }

        return (int) $row['type_id'];
    }

    protected function conditionTypeExistsInContext(int $type_id): bool
    {
        $res = $this->getDatabase()->queryF(
            'SELECT condition_id FROM lso_conditions WHERE lso_ref_id = %s AND obj_ref_id = %s AND type_id = %s',
            ['integer', 'integer', 'integer'],
            [$this->lso_ref_id, $this->obj_ref_id, $type_id]
        );

        return $this->getDatabase()->fetchAssoc($res) !== null;
    }

    /**
     * Extracts the identifier for the condition type from the class name.
     *
     * @param string $class
     * @return string
     */
    public function getIdentifierForClass(string $class): string
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
        return $this->requiresConfiguration() ? self::CONFIGURE_COMMAND : self::CREATE_COMMAND;
    }

    /**
     * Builds a bulky link for the next step in the condition setup.
     *
     * @param array $additional_parameters , e.g. subtypes
     * @param string|null $label
     * @param string|null $command , e.g. 'save' or 'configure'
     * @return Bulky
     * @throws ilCtrlException
     */
    protected function buildStep(
        array $additional_parameters = [],
        ?string $label = null,
        ?string $command = null,
    ): Bulky {
        $this->dic->ctrl()->setParameterByClass(
            ilObjLearningSequenceConditionsGUI::class,
            'type_id',
            $this->getTypeId()
        );
        $this->dic->ctrl()->setParameterByClass(
            ilObjLearningSequenceConditionsGUI::class,
            'item_ref_id',
            (string) $this->obj_ref_id
        );
        $this->dic->ctrl()->setParameterByClass(
            ilObjLearningSequenceConditionsGUI::class,
            'ref_id',
            (string) $this->lso_ref_id
        );

        foreach ($additional_parameters as $name => $value) {
            $this->dic->ctrl()->setParameterByClass(
                ilObjLearningSequenceConditionsGUI::class,
                (string) $name,
                (string) $value
            );
        }

        $uri = $this->buildUrl($command ?? $this->getStepCommand());
        $this->dic->ctrl()->clearParametersByClass(ilObjLearningSequenceConditionsGUI::class);

        return $this->ui_factory->link()->bulky(
            $this->ui_factory->symbol()->icon()->custom('', ''),
            $label ?? $this->lang->txt($this->getName()),
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
     * Determines whether multiple conditions of the same type are allowed for the same object.
     * Override this method in child classes if multiple conditions of the same type are NOT allowed.
     *
     * @return bool
     */
    public function allowMultipleConditionsOfSameType(): bool
    {
        return true;
    }

    /**
     * Returns statically analyzable input-condition constraints.
     *
     * @return array<int, array{kind: string, ref_ids: int[]}>
     */
    public function getStaticInputConditionConstraints(): array
    {
        return [];
    }

    /**
     * Allows concrete conditions to report static misconfigurations that depend
     * on context information such as the configured start object.
     *
     * @param array<string, mixed> $context
     */
    public function hasStaticInputConfigurationConflict(array $context = []): bool
    {
        return false;
    }

    /**
     * @param int[] $referenced_ref_ids
     * @param array<string, mixed> $context
     */
    protected function referencesMissingLsoItems(array $referenced_ref_ids, array $context = []): bool
    {
        $valid_ref_ids = $context['valid_ref_ids'] ?? [];
        if (!is_array($valid_ref_ids) || $valid_ref_ids === []) {
            return false;
        }

        $normalized_valid_ref_ids = array_values(array_unique(array_map('intval', $valid_ref_ids)));
        foreach (array_values(array_unique(array_map('intval', $referenced_ref_ids))) as $ref_id) {
            if (!in_array($ref_id, $normalized_valid_ref_ids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the learning sequence object associated with this condition.
     *
     * @return ilObjLearningSequence
     * @throws LogicException if the LSO ref id is not set or the object is not an ilObjLearningSequence
     */
    protected function getLso(): ilObjLearningSequence
    {
        $lso_ref_id = $this->getLsoRefId();
        if ($lso_ref_id === null) {
            throw new LogicException($this->lang->txt('lso_exception_lso_ref_id_not_set'));
        }

        /** @var ilObjLearningSequence $object */
        $object = ilObjectFactory::getInstanceByRefId($lso_ref_id);
        if (!$object instanceof ilObjLearningSequence) {
            throw new LogicException($this->lang->txt('lso_exception_not_lso_object'));
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

    /**
     * Reads the condition data from the database and populates the properties.
     *
     * @throws LogicException if the condition id is not set or the condition does not exist
     */
    public function read(): void
    {
        if ($this->condition_id === null) {
            throw new LogicException($this->lang->txt('lso_exception_condition_id_not_set'));
        }

        $res = $this->getDatabase()->queryF(
            'SELECT condition_id, lso_ref_id, obj_ref_id, type_id FROM lso_conditions WHERE condition_id = %s',
            ['integer'],
            [$this->condition_id]
        );
        $row = $this->getDatabase()->fetchAssoc($res);

        if ($row === null) {
            throw new LogicException($this->lang->txt('lso_exception_condition_not_exists'));
        }

        $this->setLsoRefId((int) $row['lso_ref_id']);
        $this->setObjRefId((int) $row['obj_ref_id']);
        $this->setTypeId((int) $row['type_id']);
        if ($this instanceof SubtypeAwareInterface) {
            $db = $this->dic->database();
            $table_name = "lsp_c_{$this->getName()}";

            if (
                $db->tableExists($table_name)
                && $db->tableColumnExists($table_name, 'condition_id')
                && $db->tableColumnExists($table_name, 'subtype')
            ) {
                $query = $this->dic->database()->queryF(
                    'SELECT * FROM %s WHERE condition_id = %s',
                    ['text', 'integer'],
                    [$table_name, $this->condition_id]
                );
                if ($record = $db->fetchObject($query)) {
                    $this->setSubtype($record->subtype);
                }
            }
        }
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        if ($this->condition_id === null) {
            return [];
        }

        $definitions = static::migrate();
        if ($definitions === []) {
            return [];
        }

        $db = $this->getDatabase();
        $result = [];

        foreach ($definitions as $def) {
            $table = $def->tableName;

            if (!$db->tableExists($table) || !$db->tableColumnExists($table, 'condition_id')) {
                continue;
            }

            $res = $db->queryF(
                'SELECT * FROM ' . $table . ' WHERE condition_id = %s',
                ['integer'],
                [$this->condition_id]
            );

            $rows = [];
            while ($row = $db->fetchAssoc($res)) {
                $rows[] = $row;
            }

            $result[] = [
                'table' => $table,
                'fields' => $def->fields,
                'rows' => $rows,
            ];
        }

        return $result;
    }

    /**
     * Default export based on migrate() table definitions.
     *
     * @return array<int, array{
     *   table:string,
     *   fields:array,
     *   rows:array<int, array<string, mixed>>
     * }>
     */
    protected function exportPayload(): array
    {
        if ($this->condition_id === null) {
            return [];
        }

        $definitions = static::migrate();
        if ($definitions === []) {
            return [];
        }

        $db = $this->getDatabase();
        $result = [];

        foreach ($definitions as $def) {
            $table = $def->tableName;
            if (!$db->tableExists($table) || !$db->tableColumnExists($table, 'condition_id')) {
                continue;
            }

            $res = $db->queryF(
                'SELECT * FROM ' . $table . ' WHERE condition_id = %s',
                ['integer'],
                [$this->condition_id]
            );

            $rows = [];
            while ($row = $db->fetchAssoc($res)) {
                $rows[] = $row;
            }

            $result[] = [
                'ilias_version_numeric' => defined('ILIAS_VERSION_NUMERIC') ? (int) ILIAS_VERSION_NUMERIC : null,
                'table' => $table,
                'fields' => $def->fields,
                'rows' => $rows,
            ];
        }

        return $result;
    }

    /**
     * Default import for payload exported by exportPayload().
     * $new_condition_id must already be created in lso_conditions.
     */
    protected function importPayload(array $payload, int $new_condition_id): void
    {
        $db = $this->getDatabase();
        $ref_mapping = $this->import_mapping;

        foreach ($payload as $tableData) {
            $table = (string)($tableData['table'] ?? '');
            $fields = (array)($tableData['fields'] ?? []);
            $rows = is_array($tableData['rows'] ?? null) ? $tableData['rows'] : [];

            $field_map = [];
            foreach ($fields as $col_name => $field_def) {
                $field_map[$col_name] = [
                    'type' => $field_def['type'] ?? 'text',
                    'nullable' => !($field_def['notnull'] ?? true),
                ];
            }

            if ($table === '' || !$db->tableExists($table)) {
                continue;
            }

            $table_has_item_column = $db->tableColumnExists($table, 'item_ref_id');

            if ($table_has_item_column && $rows === []) {
                throw new \RuntimeException("Payload for table '{$table}' is empty, but table requires 'item' rows.");
            }

            $inserted_item_rows = 0;

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $insert = [
                    'condition_id' => ['integer', $new_condition_id],
                ];

                foreach ($row as $col => $value) {
                    $col = (string) $col;

                    // Always enforce the new condition id
                    if ($col === 'condition_id') {
                        continue;
                    }

                    // Only import columns that actually exist in the target table
                    if (!$db->tableColumnExists($table, $col)) {
                        continue;
                    }

                    $field_info = $field_map[$col] ?? null;

                    // Remap ref_id values using the container refs mapping
                    if ($col === 'item_ref_id') {
                        if (!isset($ref_mapping[$value])) {
                            continue 2;
                        }

                        $value = (int) $ref_mapping[$value];
                        $insert['item_ref_id'] = ['integer', $value];
                        $inserted_item_rows++;
                    }

                    // Handle NULL values based on field definition
                    if ($value === null) {
                        if ($field_info && ($field_info['nullable'] ?? false)) {
                            $insert[$col] = [$field_info['type'], null];
                        } else {
                            $insert[$col] = [$field_info ? $field_info['type'] : 'text', ''];
                        }
                        continue;
                    }

                    $type = $field_info ? $field_info['type'] : (is_int($value) ? 'integer' : 'text');
                    $insert[$col] = [$type, $value];
                }

                $db->insert($table, $insert);
            }

            if ($table_has_item_column && $inserted_item_rows === 0) {
                throw new \RuntimeException("No valid 'item' rows imported for item-based table '{$table}'.");
            }
        }
    }

    final public function export(): array
    {
        return $this->exportPayload();
    }

    final public function import(array $payload, int $new_condition_id): void
    {
        $this->importPayload($payload, $new_condition_id);
    }

    public function setImportMapping(array $mapping): void
    {
        $this->import_mapping = $mapping;
    }
}
