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

namespace ILIAS\LearningSequence\Content\Condition\InputCondition\SubsetInputCondition;

use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionNavigationAwareInterface;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\TableDefinition;
use ILIAS\LearningSequence\Content\Condition\LSOObjectPicker;
use ILIAS\UI\Component\Input\Container\Form\Standard as FormStandard;
use ILIAS\UI\Component\Symbol\Glyph\Glyph;

/**
 * Input condition that grants access to a learning sequence step once a configurable
 * subset of the selected objects has been completed by the current user.
 *
 * The condition stores a list of object references together with the minimum number
 * of them ("subset") that must be completed. When {@see self::check()} is evaluated,
 * the learning progress of the logged in user is inspected for each configured object
 * and access is granted as soon as the number of completed objects reaches the
 * required subset size.
 */
class SubsetInputCondition extends AbstractCondition implements InputConditionInterface, InputConditionNavigationAwareInterface
{
    protected const string NAME = 'subset';
    private const string SETTINGS_TABLE = 'lso_c_subset';
    private const string OBJECT_IDS_FIELD = 'object_ids';
    private const string SUBSET_FIELD = 'subset';

    /**
     * @var int[]|null
     */
    private ?array $object_ref_ids = null;
    private ?int $subset = null;
    /**
     * Provides the database table definitions required by this condition.
     *
     * The condition needs its own settings table ({@see self::SETTINGS_TABLE}) that
     * stores, per condition, the serialized list of selected object references and the
     * minimum amount of them that has to be completed. The returned definitions are
     * created and kept up to date during the ILIAS setup/update process.
     *
     * @return TableDefinition[] the table definitions to be installed for this condition
     */
    public static function migrate(): array
    {

        return [
            new TableDefinition(
                tableName: self::SETTINGS_TABLE,
                fields: [
                    'condition_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
                    self::OBJECT_IDS_FIELD => ['type' => 'text', 'length' => 4000, 'notnull' => false],
                    self::SUBSET_FIELD => ['type' => 'integer', 'length' => 4, 'notnull' => false]
                ],
                primaryKeys: ['condition_id']
            )
        ];
    }

    /**
     * Checks whether this condition is fulfilled for the current user and context.
     *
     * The configured list of objects and the required subset size are loaded from the
     * settings table for the current condition. For every configured object the learning
     * progress of the logged in user is inspected and completed objects are counted.
     * The condition is fulfilled as soon as the number of completed objects reaches the
     * required subset size.
     *
     * @return bool true if at least the required number of objects has been completed, false otherwise
     */
    public function check(): bool
    {
        $user_id = $this->dic->user()->getId();
        $completed_counter = 0;
        foreach ($this->getObjectRefIds() as $object_ref_id) {
            $object_id = \ilObject::_lookupObjId($object_ref_id);
            if ($object_id > 0 && \ilLPStatus::_hasUserCompleted($object_id, $user_id)) {
                $completed_counter++;
            }
        }

        return $completed_counter >= $this->getSubset();
    }

    public function getNavigationMode(): string
    {
        return InputConditionNavigationAwareInterface::NAVIGATION_MODE_DEPENDENCY;
    }

    public function getNavigationSourceRefIds(): array
    {
        return $this->getObjectRefIds();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function hasStaticInputConfigurationConflict(array $context = []): bool
    {
        return $this->referencesMissingLsoItems($this->getObjectRefIds(), $context);
    }

    /**
     * Builds the configuration form of this condition.
     *
     * The form offers a multi select ({@see LSOObjectPicker}) that lets the author pick
     * the relevant objects, a numeric field for the minimum number of objects that have
     * to be completed and a hidden field carrying the condition type id. The current
     * context is validated via {@see self::assertContextSet()} before the form is built.
     *
     * @return FormStandard|null the configuration form, or null if no additional form is required
     */
    public function getAdditionalForm(): ?FormStandard
    {
        $this->assertContextSet();

        $multi_select = new LSOObjectPicker((int) $this->lso_ref_id, (int) $this->getObjRefId())->getPicker(
            $this->lang->txt('lso_condition_simple_multi_target'),
            true
        );

        $required_amount = $this->ui_factory->input()->field()->numeric(
            $this->lang->txt('subset_amount'),
            $this->lang->txt('subset_amount_byline'),
        )->withRequired(true);

        if ($this->condition_id !== null) {
            $multi_select = $multi_select->withValue(
                array_map(static fn(int $ref_id): string => (string) $ref_id, $this->getObjectRefIds())
            );
            $required_amount = $required_amount->withValue($this->getSubset());
        }

        return $this->ui_factory->input()->container()->form()->standard(
            $this->buildUrl(self::CREATE_COMMAND, true)->__toString(),
            [
                $multi_select,
                $required_amount,
            ]
        );
    }

    public function getAdditionalDisplayInformation(): string
    {
        return sprintf('%s: %d', $this->lang->txt('subset_amount'), $this->getSubset());
    }

    /**
     * @return string[]
     */
    public function getAdditionalDisplayObjectTitles(): array
    {
        return array_map(
            fn(int $ref_id): string => $this->getObjectTitleByRefId($ref_id),
            $this->getObjectRefIds()
        );
    }

    /**
     * @param array $data
     */
    public function applyAdditionalFormData(array $data): void
    {
        $object_ref_ids = array_values(array_filter(
            array_map(
                static fn(mixed $value): int => (int) $value,
                is_array($data[0] ?? null) ? $data[0] : []
            ),
            static fn(int $value): bool => $value > 0
        ));

        $subset = $data[1] ?? null;
        if (is_array($subset) || !is_numeric($subset)) {
            throw new \LogicException($this->lang->txt('lso_exception_subset_invalid'));
        }

        $this->setObjectRefIds($object_ref_ids);
        $this->setSubset((int) $subset);
    }

    /**
     * @return int[]
     */
    public function getObjectRefIds(): array
    {
        if ($this->object_ref_ids !== null) {
            return $this->object_ref_ids;
        }

        if ($this->condition_id === null) {
            return [];
        }

        $res = $this->getDatabase()->queryF(
            'SELECT ' . self::OBJECT_IDS_FIELD . ' FROM ' . self::SETTINGS_TABLE . ' WHERE condition_id = %s',
            ['integer'],
            [$this->condition_id]
        );
        $row = $this->getDatabase()->fetchAssoc($res);
        if ($row === null || !array_key_exists(self::OBJECT_IDS_FIELD, $row)) {
            throw new \LogicException($this->lang->txt('lso_exception_object_ids_not_stored'));
        }

        $object_ref_ids = @unserialize((string) $row[self::OBJECT_IDS_FIELD]);
        if (!is_array($object_ref_ids)) {
            throw new \LogicException($this->lang->txt('lso_exception_object_ids_invalid'));
        }

        $this->object_ref_ids = array_values(array_filter(
            array_map(static fn(mixed $value): int => (int) $value, $object_ref_ids),
            static fn(int $value): bool => $value > 0
        ));

        return $this->object_ref_ids;
    }

    /**
     * @param int[] $object_ref_ids
     */
    public function setObjectRefIds(array $object_ref_ids): void
    {
        $object_ref_ids = array_values(array_filter(
            array_map(static fn(mixed $value): int => (int) $value, $object_ref_ids),
            static fn(int $value): bool => $value > 0
        ));

        if ($object_ref_ids === []) {
            throw new \LogicException($this->lang->txt('lso_exception_at_least_one_object'));
        }

        $this->object_ref_ids = $object_ref_ids;
    }

    /**
     * @return int
     */
    public function getSubset(): int
    {
        if ($this->subset !== null) {
            return $this->subset;
        }

        if ($this->condition_id === null) {
            return 0;
        }

        $res = $this->getDatabase()->queryF(
            'SELECT ' . self::SUBSET_FIELD . ' FROM ' . self::SETTINGS_TABLE . ' WHERE condition_id = %s',
            ['integer'],
            [$this->condition_id]
        );
        $row = $this->getDatabase()->fetchAssoc($res);
        if ($row === null || !isset($row[self::SUBSET_FIELD])) {
            throw new \LogicException($this->lang->txt('lso_exception_subset_not_stored'));
        }

        $this->subset = (int) $row[self::SUBSET_FIELD];
        return $this->subset;
    }

    /**
     * @param int $subset
     * @return void
     */
    public function setSubset(int $subset): void
    {
        if ($subset < 0) {
            throw new \LogicException($this->lang->txt('lso_exception_subset_negative'));
        }

        if ($this->object_ref_ids !== null && $subset > count($this->object_ref_ids)) {
            throw new \LogicException($this->lang->txt('lso_exception_subset_exceeds_objects'));
        }

        $this->subset = $subset;
    }

    /**
     * @param int $condition_id
     * @return void
     */
    protected function createConditionData(int $condition_id): void
    {
        $this->getDatabase()->insert(self::SETTINGS_TABLE, [
            'condition_id' => ['integer', $condition_id],
            self::OBJECT_IDS_FIELD => ['text', serialize($this->requireObjectRefIds())],
            self::SUBSET_FIELD => ['integer', $this->requireSubset()]
        ]);
    }

    /**
     * @param int $condition_id the id of the condition the data belongs to
     */
    protected function editConditionData(int $condition_id): void
    {
        $this->getDatabase()->update(
            self::SETTINGS_TABLE,
            [
                self::OBJECT_IDS_FIELD => ['text', serialize($this->requireObjectRefIds())],
                self::SUBSET_FIELD => ['integer', $this->requireSubset()]
            ],
            [
                'condition_id' => ['integer', $condition_id]
            ]
        );
    }

    /**
     * Removes the stored configuration data of a condition.
     *
     * The row identified by the condition id and the referenced learning sequence object
     * is deleted from the settings table.
     *
     * @param int $condition_id the id of the condition whose data should be removed
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
     * Indicates that this condition needs to be configured before it can be used.
     *
     * Because the author has to select the relevant objects and the required subset
     * size, the configuration form has to be shown, hence this returns true.
     *
     * @return bool always true for this condition
     */
    protected function requiresConfiguration(): bool
    {
        return true;
    }

    /**
     * Returns the glyph used to represent this condition in the user interface.
     *
     * @return Glyph the glyph symbol for this condition
     */
    protected function getGlyphe(): Glyph
    {
        return $this->ui_factory->symbol()->glyph()->checked();
    }

    /**
     * @return int[]
     */
    private function requireObjectRefIds(): array
    {
        if ($this->object_ref_ids === null || $this->object_ref_ids === []) {
            throw new \LogicException($this->lang->txt('lso_exception_object_ids_not_set'));
        }

        return $this->object_ref_ids;
    }

    /**
     * @return int
     */
    private function requireSubset(): int
    {
        if ($this->subset === null) {
            throw new \LogicException($this->lang->txt('lso_exception_subset_not_set'));
        }

        return $this->subset;
    }
}
