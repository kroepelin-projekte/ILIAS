<?php

declare(strict_types=1);


use ilObjLearningSequenceGUI;
use ilCtrl;
use ilGlobalTemplateInterface;
use ilLanguage;
use ilAccessHandler;
use ilDBInterface;

/**
 * Class ilObjLearningSequenceConditionsGUI
 *
 * @ilCtrl_isCalledBy      ilObjLearningSequenceConditionsGUI: ilObjLearningSequenceGUI, ilObjLearningSequenceContentGUI
 */
class ilObjLearningSequenceConditionsGUI
{
    public const CMD_VIEW = 'view';
    protected ilObjLearningSequenceGUI $parent_gui;
    protected ilCtrl $ctrl;
    protected ilGlobalTemplateInterface $tpl;
    protected ilLanguage $lng;
    protected ilAccessHandler $access;
    protected ilDBInterface $db;

    public function __construct(
        ilObjLearningSequenceGUI $parent_gui,
        ilCtrl $ctrl,
        ilGlobalTemplateInterface $tpl,
        ilLanguage $lng,
        ilAccessHandler $access,
        ilDBInterface $db
    ) {
        $this->parent_gui = $parent_gui;
        $this->ctrl = $ctrl;
        $this->tpl = $tpl;
        $this->lng = $lng;
        $this->access = $access;
        $this->db = $db;
    }

    public function executeCommand(): void
    {
        $cmd = $this->ctrl->getCmd("view");
        switch ($cmd) {
            case "view":
            default:
                $this->view();
                break;
        }
    }

    protected function view(): void
    {
        $this->tpl->setContent("Conditions View (Placeholder)");
    }
}
