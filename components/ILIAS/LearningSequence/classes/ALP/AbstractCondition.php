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

namespace ILIAS;

abstract class AbstractCondition
{
    protected const NAME = null;
    protected const SAVE = 'save';

    protected \ilLanguage $lang;
    protected \ILIAS\DI\Container $dic;
    // TODO: Ist null ein legitimer Wert? Szenario: Objekt wurde gelöscht, aber die Condition ist noch da?
    protected ?int $obj_ref_id;
    protected \ILIAS\UI\Factory $ui_factory;

    public function __construct()
    {
        global $DIC;
        /** @var \ILIAS\DI\Container $DIC */
        $this->dic = $DIC;
        $this->lang = $this->dic->language();
        $this->obj_ref_id = null;
        $this->ui_factory = $this->dic->ui()->factory();
    }

    /**
     * @return array
     */
    abstract public function migrate(): array;

    /**
     * @return array
     */
    abstract public function setupSteps(): array;

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
     * @return Standard[]
     */
    public function getAdditionalForm(): array
    {
        return [];
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->lang->txt(static::NAME);
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
     * Saves the condition
     */
    public function save(): void
    {
        // TODO: To implement
    }

    /**
     * Edits the condition.
     */
    public function edit(): void
    {
        // TODO: To implement
    }

    /**
     * Deletes the condition from the database.
     */
    public function delete(): void
    {
        // TODO: To implement
    }
}
