<?php

declare(strict_types=1);

use ILIAS\Data\URI;
use ILIAS\HTTP\Wrapper\ArrayBasedRequestWrapper;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;
use ILIAS\UI\Component\Table\Table;
use ILIAS\UI\URLBuilder;

/**
 * @ilCtrl_isCalledBy ilObjLearningSequenceConditionsGUI: ilObjLearningSequenceContentGUI
 */
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
        protected ArrayBasedRequestWrapper $post_wrapper,
        protected ILIAS\UI\Factory $ui_factory,
        protected ILIAS\UI\Renderer $ui_renderer
    ) {
    }

    public function executeCommand(): void
    {
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

        $modal = $this->buildAddConditionModal();
        $button = $this->ui_factory->button()->standard('Add condition', '#')->withOnClick($modal->getShowSignal());
        $DIC->toolbar()->addComponent($button);

        $this->tpl->addInlineCss(".c-table-data__table { width: 80%; }");

        $this->tpl->setContent(
            $this->ui_renderer->render([
                $this->buildLayout(),
                $modal,
            ])
        );
    }

    protected function buildAddConditionModal()
    {
        return $this->ui_factory->modal()->roundtrip(
            'Add condition',
            [
                'drilldown' => $this->getDrilldown()
            ]
        );
    }

    protected function buildLayout(): \ILIAS\UI\Component\Layout\Alignment\Horizontal\EvenlyDistributed
    {
        $this->tpl->addInlineCss(".fullwidth {width: 100%;}");
        return $this->ui_factory->layout()->alignment()->horizontal()->evenlyDistributed(
            $this->ui_factory->legacy()->content('<div class="fullwidth">' . $this->ui_renderer->render($this->buildInputConditionsTable()) . '</div>'),
            $this->ui_factory->legacy()->content('<div class="fullwidth">' . $this->ui_renderer->render($this->buildOutputConditionsTable()) . '</div>'),
        );
    }

    protected function getDrilldown()
    {
        $conditions = []; // interface reader

        $input_conditions = [];
        $output_conditions = [];
/*        foreach ($conditions as $condition) {
            if ($condition instanceof InputConditionInterface) {
                $input_conditions = $condition->getMenuButtons();
            } elseif ($condition instanceof OutputConditionInterface) {
                $output_conditions = $condition->getMenuButtons();
            }
        }*/

        $icon  = $this->ui_factory->symbol()->icon()->custom('', '');
        $input_conditions = [
            $this->ui_factory->menu()->sub(
                'Always',
                [
                    $this->ui_factory->link()->bulky(
                        $icon,
                        'Add Condition',
                        new URI(ILIAS_HTTP_PATH)
                    )
                ]
            ),
            $this->ui_factory->menu()->sub(
                'Points',
                [
                    $this->ui_factory->link()->bulky(
                        $icon,
                        'Add Condition',
                        new URI(ILIAS_HTTP_PATH)
                    )
                ]
            )
        ];

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

    private function buildInputConditionsTable(): Table
    {
        // todo parameter
        global $DIC;
        $request = $DIC->http()->request();

        $data_factory = new \ILIAS\Data\Factory();

        $example_uri = $data_factory->uri((string) $request->getUri());
        $url_builder = new URLBuilder($example_uri);
        [$process_form_url_builder, $process_form_parameter] = $url_builder->acquireParameter(explode('\\', __NAMESPACE__), "process_single");


        return $this->ui_factory->table()->data(
            new ilLearningSequenceConditionsInputRetrieval(),
            'Input Conditions', // todo lang
            [
                'input_condition_type' => $this->ui_factory->table()->column()->text('Type'),
            ]
        )
            ->withActions([
                $this->ui_factory->table()->action()->single('Edit', $process_form_url_builder, $process_form_parameter),
                $this->ui_factory->table()->action()->single('Delete', $process_form_url_builder, $process_form_parameter),
            ])
            ->withRequest($request);
    }

    private function buildOutputConditionsTable(): Table
    {
        // todo parameter
        global $DIC;
        $request = $DIC->http()->request();

        $data_factory = new \ILIAS\Data\Factory();

        $example_uri = $data_factory->uri((string) $request->getUri());
        $url_builder = new URLBuilder($example_uri);
        [$process_form_url_builder, $process_form_parameter] = $url_builder->acquireParameter(explode('\\', __NAMESPACE__), "process_single");


        return $this->ui_factory->table()->data(
            new ilLearningSequenceConditionsOutpubRetrieval(),
            'Output Conditions', // todo lang
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
