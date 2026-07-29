<?php

declare(strict_types=1);

/**
 * @ilCtrl_isCalledBy ilObjLearningSequenceEditConditionGUI: ilObjLearningSequenceConditionsGUI
 * @ilCtrl_Calls ilObjLearningSequenceEditConditionGUI: ilObjLearningSequenceConditionsGUI
 */
class ilObjLearningSequenceEditConditionGUI
{
    private ilCtrlInterface $ctrl;
    private ilGlobalTemplateInterface $tpl;

    public function __construct()
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
    }

    public const string CMD_EDIT_CONDITION = "editCondition";


    public function executeCommand(): void
    {
        global $DIC;
        $DIC->tabs()->setBack2Target('Back', $this->ctrl->getLinkTargetByClass(ilObjLearningSequenceConditionsGUI::class, ilObjLearningSequenceConditionsGUI::CMD_MANAGE_CONDITIONS));

        $cmd = $this->ctrl->getCmd();

        switch ($cmd) {
            case self::CMD_EDIT_CONDITION:
                $this->$cmd();
                break;
            default:
                throw new ilException("ilObjLearningSequenceConditionGUI: Command not supported: $cmd");
        }
    }

    protected function editCondition(): void
    {
        $this->tpl->setContent('Condition');
    }

    /**
     * @throws ilCtrlException
     */
    public static function getUrl(int $condition_id): string
    {
        global $DIC;
        $url = $DIC->ctrl()->getLinkTargetByClass(
            ilObjLearningSequenceEditConditionGUI::class,
            ilObjLearningSequenceEditConditionGUI::CMD_EDIT_CONDITION
        );
        return $url;
    }
}
