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
use ILIAS\Refinery\Factory;
use ILIAS\UI\Component\Table\Table;
use ILIAS\UI\URLBuilder;

class ilObjLearningSequenceConditionsGUI
{
    public const string CMD_MANAGE_CONDITIONS = "manageConditions";

    public function __construct(
        protected ilObjLearningSequenceContentGUI $content_gui,
        protected ilObjLearningSequenceGUI $parent_gui,
        protected ilCtrl $ctrl,
        protected ilGlobalTemplateInterface $tpl,
        protected ilLanguage $lng,
        protected ilAccess $access,
        protected ilConfirmationGUI $confirmation_gui,
        protected LSItemOnlineStatus $ls_item_online_status,
        protected ArrayBasedRequestWrapper $post_wrapper,
        protected Factory $refinery,
        protected ILIAS\UI\Factory $ui_factory,
        protected ILIAS\UI\Renderer $ui_renderer
    ) {
    }

    public function executeCommand(): void
    {
        if (!$this->access->checkAccess("read", '', $this->parent_gui->getRefId())) {
            $this->tpl->setOnScreenMessage('info', sprintf(
                $this->lng->txt('msg_no_perm_read_item'),
                $this->parent_gui->getObjTitle()
            ), true);

            $this->ctrl->redirect($this->parent_gui, 'view');
        }

        $cmd = $this->ctrl->getCmd();

        switch ($cmd) {
            case self::CMD_MANAGE_CONDITIONS:
                $this->$cmd();
                break;
            default:
                throw new ilException("ilObjLearningSequenceConditionsGUI: Command not supported: $cmd");
        }
    }

    protected function manageConditions(): void
    {
        // todo braucht man einen back tab?
        global $DIC;
        $DIC->tabs()->setBack2Target('Back', $this->ctrl->getLinkTarget($this->content_gui, $this->content_gui::CMD_MANAGE_CONTENT));
        $this->tpl->setContent($this->ui_renderer->render($this->getConditionsTable()));
    }

    protected function getDrilldown(): \ILIAS\UI\Component\Menu\Drilldown
    {
        $conditions = []; // interface reader

        $input_conditions = [];
        $output_conditions = [];
        foreach ($conditions as $condition) {
            if ($condition instanceof InputCondition) {
                $input_conditions = $condition->getMenuButtons();
            } elseif ($condition instanceof OutputCondition) {
                $output_conditions = $condition->getMenuButtons();
            }
        }

        return $this->ui_factory->menu()->drilldown(
            'Manage Conditions',
            [
                $this->ui_factory->menu()->sub(
                    'Input Conditions',
                    $input_conditions
                ),
                $this->ui_factory->menu()->sub(
                    'Output Conditions',
                    $output_conditions
                )
            ]
        );
    }

    private function getConditionsTable(): Table
    {
        // todo parameter
        global $DIC;
        $request = $DIC->http()->request();

        $data_factory = new \ILIAS\Data\Factory();

        $example_uri = $data_factory->uri((string) $request->getUri());
        $url_builder = new URLBuilder($example_uri);
        [$process_form_url_builder, $process_form_parameter] = $url_builder->acquireParameter(explode('\\', __NAMESPACE__), "process_single");


        return $this->ui_factory->table()->data(
            new ilLearningSequenceConditionsTableRetrieval(),
            'Conditions', // todo lang
            [
                'condition_type' => $this->ui_factory->table()->column()->text('Type'),
            ]
        )
            ->withActions([
                $this->ui_factory->table()->action()->single('Action', $process_form_url_builder, $process_form_parameter)
            ])
            ->withRequest($request);
    }
}
