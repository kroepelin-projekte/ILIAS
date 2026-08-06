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
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\TableDefinition;
use ILIAS\LearningSequence\Content\Condition\LSOObjectPicker;
use ILIAS\UI\Component\Input\Container\Form\Standard as FormStandard;

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
class SubsetInputCondition extends AbstractCondition implements InputConditionInterface
{
    protected const string NAME = 'subset';
    private const SETTINGS_TABLE = 'lso_c_subset';
    /**
     * Provides the database table definitions required by this condition.
     *
     * The condition needs its own settings table ({@see self::SETTINGS_TABLE}) that
     * stores, per condition, the referenced learning sequence object, the serialized
     * list of selected object references and the minimum amount of them that has to be
     * completed. The returned definitions are created and kept up to date during the
     * ILIAS setup/update process.
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
                    'object_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true],
                    'object_ids' => ['type' => 'text', 'length' => 4000, 'notnull' => false],
                    'subsets' => ['type' => 'integer', 'length' => 8, 'notnull' => false]
                ],
                primaryKeys: ['condition_id']
            )
        ];
    }

    /**
     * Checks whether this condition is fulfilled for the current user and context.
     *
     * The configured list of objects and the required subset size are loaded from the
     * settings table for the current condition and learning sequence object. For every
     * configured object the learning progress of the logged in user is inspected and
     * completed objects are counted. The condition is fulfilled as soon as the number
     * of completed objects reaches the required subset size.
     *
     * @return bool true if at least the required number of objects has been completed, false otherwise
     */
    public function check(): bool
    {
        $this->assertContextSet();

        $condition_id = $this->resolveConditionId();
        $lso_object_id = $this->getLso()->getId();

        $res = $this->getDatabase()->queryF(
            'SELECT object_ids, subset FROM ' . self::SETTINGS_TABLE
            . ' WHERE condition_id = %s AND object_id = %s',
            ['integer', 'integer'],
            [$condition_id, $lso_object_id]
        );
        $row = $this->getDatabase()->fetchAssoc($res);

        if ($row === null) {
            return false;
        }

        $object_ids = @unserialize((string) $row['object_ids']);
        if (!is_array($object_ids)) {
            $object_ids = [];
        }
        $required_amount = (int) $row['subset'];

        $user_id = $this->dic->user()->getId();
        $completed_counter = 0;
        foreach ($object_ids as $object_id) {
            if (\ilLPStatus::_hasUserCompleted((int) $object_id, $user_id)) {
                $completed_counter++;
            }
        }

        return $completed_counter >= $required_amount;
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

        $multi_select = (new LSOObjectPicker((int) $this->lso_ref_id))->getPicker(
            'Objektauswahl', // TODO Sprachvariable
            true
        );

        $required_amount = $this->ui_factory->input()->field()->numeric(
            'Anzahl der bestehende Objekte', // TODO Sprachvariable
            'Die Anzahl der Objekte die zu bestehen sind, damit dieses Objekt startet' // TODO Sprachvariable
        )->withRequired(true);

        return $this->ui_factory->input()->container()->form()->standard(
            $this->buildUrl(self::CREATE_COMMAND, true)->__toString(),
            [
                $multi_select,
                $required_amount,
            ]
        );
    }

    /**
     * Persists the initial configuration data for a newly created condition.
     *
     * A new row holding the referenced learning sequence object, the serialized list of
     * selected objects and the required subset size is inserted into the settings table.
     *
     * @param int $condition_id the id of the condition the data belongs to
     *
     * TODO: read the actual selected object ids from the submitted form data.
     */
    protected function createConditionData(int $condition_id): void
    {
        $lso_object_id = $this->getLsoRefId();
        $object_ids = serialize(''); #ToDo Daten holen
        $subset = 0;
        $this->getDatabase()->insert(self::SETTINGS_TABLE, [
            'condition_id' => ['integer', $condition_id],
            'object_id' => ['integer', $lso_object_id],
            'object_ids' => ['text', $object_ids],
            'subset' => ['integer', $subset]
        ]);
    }

    /**
     * Updates the stored configuration data of an existing condition.
     *
     * The serialized list of selected objects and the required subset size are updated
     * for the row identified by the condition id and the referenced learning sequence
     * object.
     *
     * @param int $condition_id the id of the condition the data belongs to
     *
     * TODO: read the actual selected object ids from the submitted form data.
     */
    protected function editConditionData(int $condition_id): void
    {
        $lso_object_id = $this->getLsoRefId();
        $object_ids = serialize(''); #ToDo Daten holen
        $subset = 0;
        $this->getDatabase()->update(
            self::SETTINGS_TABLE,
            [
                'object_ids' => ['text', $object_ids],
                'subset' => ['integer', $subset]
            ],
            [
                'condition_id' => ['integer', $condition_id],
                'object_id' => ['integer', $lso_object_id]
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
        $lso_object_id = $this->getLsoRefId();
        $this->getDatabase()->manipulateF(
            'DELETE FROM ' . self::SETTINGS_TABLE . ' WHERE condition_id = %s AND object_id = %s',
            ['integer', 'integer'],
            [$condition_id, $lso_object_id]
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
     * @return \ILIAS\UI\Component\Symbol\Glyph\Glyph the glyph symbol for this condition
     */
    protected function getGlyphe(): \ILIAS\UI\Component\Symbol\Glyph\Glyph
    {
        return $this->ui_factory->symbol()->glyph()->checked();
    }
}
