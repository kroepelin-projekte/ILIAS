<?php

declare(strict_types=1);

/**
 * @ilCtrl_isCalledBy ilObjLearningSequenceEditConditionGUI: ilObjLearningSequenceConditionsGUI
 */
class ilObjLearningSequenceEditConditionGUI
{
    private ilCtrlInterface $ctrl;
    private ilGlobalTemplateInterface $tpl;
    private int $condition_id;

    public function __construct()
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();

        $query = $DIC->http()->wrapper()->query();
        $int_list = $DIC->refinery()->kindlyTo()->listOf($DIC->refinery()->kindlyTo()->int());
        if (!$query->has('condition_id')) {
            throw new ilException('Permission denied');
        }
        $this->condition_id = current($query->retrieve('condition_id', $int_list));
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
        $this->tpl->setContent('Condition: ' . $this->condition_id);
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
