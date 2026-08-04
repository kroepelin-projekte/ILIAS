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

use ILIAS\HTTP\Wrapper\ArrayBasedRequestWrapper;
use ILIAS\UI\Component\Input\Container\Form\Standard;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @ilCtrl_isCalledBy ilObjLearningSequenceEditConditionGUI: ilObjLearningSequenceConditionsGUI
 */
class ilObjLearningSequenceEditConditionGUI
{
    protected int $item_ref_id;
    protected int $condition_id;
    private ArrayBasedRequestWrapper $query;
    /**
     * @var \ILIAS\DI\Container|mixed
     */
    private mixed $dic;

    public function __construct(
        protected ilCtrl                          $ctrl,
        protected ilGlobalTemplateInterface       $tpl,
        protected ilLanguage                      $lng,
        protected ilAccess                        $access,
        protected ArrayBasedRequestWrapper        $post_wrapper,
        protected ILIAS\UI\Factory                $ui_factory,
        protected ILIAS\UI\Renderer               $ui_renderer,
        protected ServerRequestInterface          $request,
    )
    {
        global $DIC;
        $this->dic = $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->query = $DIC->http()->wrapper()->query();
        $this->item_ref_id = $this->query->retrieve('item_ref_id', $DIC->refinery()->kindlyTo()->int());
    }

    public const string CMD_CREATE_CONDITION = "createCondition";
    public const string CMD_EDIT_CONDITION = "editCondition";


    public function executeCommand(): void
    {
        global $DIC;
        $DIC->tabs()->setBack2Target('Back', $this->ctrl->getLinkTargetByClass(ilObjLearningSequenceConditionsGUI::class, ilObjLearningSequenceConditionsGUI::CMD_MANAGE_CONDITIONS));

        $cmd = $this->ctrl->getCmd();

        switch ($cmd) {
            case self::CMD_EDIT_CONDITION:
            $this->initConditionId();
            case self::CMD_CREATE_CONDITION:
                $this->$cmd();
                break;
            default:
                throw new ilException("ilObjLearningSequenceConditionGUI: Command not supported: $cmd");
        }
    }

    private function initConditionId(): void
    {
        $int_list = $this->dic->refinery()->kindlyTo()->listOf($this->dic->refinery()->kindlyTo()->int());
        if (!$this->query->has('condition_id')) {
            throw new ilException('Permission denied');
        }
        $this->condition_id = current($this->query->retrieve('condition_id', $int_list));
    }

    protected function editCondition(): void
    {
        $this->tpl->setContent(
            'Condition: ' . $this->condition_id
            . $this->ui_renderer->render($this->initConditionForm())
        );
    }

    /**
     * @throws ilCtrlException
     */
    private function initConditionForm(): Standard
    {
        // todo get form from condition

        $form = $this->ui_factory->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this),
            [

            ]
        );

        if ($this->request->getMethod() === 'POST') {
            $form = $form->withRequest($this->request);
        }

        return $form;
    }

    private function saveConditionForm(): void
    {
        $form = $this->initConditionForm()->withRequest($this->request);
        $form_data = $form->getData();
        if ($form->getError()) {
            $this->editCondition();
        }
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
