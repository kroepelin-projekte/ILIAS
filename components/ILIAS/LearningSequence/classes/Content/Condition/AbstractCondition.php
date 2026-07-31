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
     * @return array
     */
    abstract public static function migrate(): array;

    /**
     * Checks if the condition is fulfilled.
     *
     * @return bool
     */
    abstract public function check(): bool;

    /**
     * Returns the additional form for the condition.
     * Has to be implemented by the child class if additional form is needed.
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

    public function getLsoRefId(): ?int
    {
        return $this->lso_ref_id;
    }

    public function setLsoRefId(?int $lso_ref_id): void
    {
        $this->lso_ref_id = $lso_ref_id;
    }

    public function getConditionId(): ?int
    {
        return $this->condition_id;
    }

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

        $condition_id = $this->resolveConditionId();
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
        $condition_id = $this->resolveConditionId();

        $this->deleteConditionData($condition_id);

        $this->getDatabase()->manipulateF(
            'DELETE FROM lso_conditions WHERE condition_id = %s',
            ['integer'],
            [$condition_id]
        );

        $this->condition_id = null;
    }

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

    protected function buildIcon(string $abbreviation): \ILIAS\UI\Component\Symbol\Icon\Icon
    {
        return $this->ui_factory->symbol()->icon()->standard('', '')->withSize('small')->withAbbreviation($abbreviation);
    }

    protected function assertContextSet(): void
    {
        if ($this->lso_ref_id === null || $this->obj_ref_id === null) {
            throw new \LogicException('Condition context is incomplete.');
        }
    }

    protected function withCurrentContext(AbstractCondition $condition): AbstractCondition
    {
        $this->assertContextSet();
        $condition->setLsoRefId($this->lso_ref_id);
        $condition->setObjRefId($this->obj_ref_id);
        return $condition;
    }

    // NOTE: Die drei folgenden Methoden müssen in den Coditionsklassen implementiert werden, wenn mit migrate()
    // zusätzliche Tabellen angelegt werden. Diese zusätzlichen Tabellen sollen damit befüllt, bearbeitet und gelöscht werden.
    protected function createConditionData(int $condition_id): void
    {
    }

    protected function editConditionData(int $condition_id): void
    {
    }

    protected function deleteConditionData(int $condition_id): void
    {
    }

    protected function getDatabase(): ilDBInterface
    {
        return $this->dic->database();
    }

    protected function getTypeId(): int
    {
        $res = $this->getDatabase()->queryF(
            'SELECT type_id FROM lso_condition_types WHERE condition_name = %s',
            ['text'],
            [$this->getName()]
        );
        $row = $this->getDatabase()->fetchAssoc($res);

        if ($row === null) {
            throw new \LogicException('Condition type is not registered.');
        }

        return (int) $row['type_id'];
    }

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

    protected function getConditionType(): string
    {
        $is_input = $this instanceof \ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
        $is_output = $this instanceof \ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;

        if ($is_input && $is_output) {
            throw new \LogicException(
                'Condition must implement only one of InputConditionInterface or OutputConditionInterface.'
            );
        }

        return $is_input
         ? "InputCondition"
         : "OutputCondition";
    }

    // TODO: Prüfen, ob wir diese beiden Helper brauchen und wo wir sie hinpacken
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

    protected function getLsoItems(): array
    {
        return $this->getLso()->getLSItems();
    }

    protected function getStepCommand(): string
    {
        return $this->requiresConfiguration() ? self::CONFIGURE_COMMAND : self::SAVE_COMMAND;
    }

    protected function requiresConfiguration(): bool
    {
        return false;
    }
}
