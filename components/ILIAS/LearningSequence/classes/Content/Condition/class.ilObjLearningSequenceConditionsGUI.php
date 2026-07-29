<?php

declare(strict_types=1);

use ILIAS\Data\Factory;
use ILIAS\Data\URI;
use ILIAS\HTTP\Wrapper\ArrayBasedRequestWrapper;
use ILIAS\UI\Component\Table\Table;
use ILIAS\UI\URLBuilder;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @ilCtrl_isCalledBy ilObjLearningSequenceConditionsGUI: ilObjLearningSequenceContentGUI
 */
class ilObjLearningSequenceConditionsGUI
{
    public const string CMD_MANAGE_CONDITIONS = "manageConditions";
    public const string SAVE = "save";

    /** @var int LSO content object */
    protected int $item_ref_id;

    public function __construct(
        protected ilObjLearningSequenceContentGUI $content_gui,
        protected ilObjLearningSequenceGUI        $parent_gui,
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
        $this->item_ref_id = $DIC->http()->wrapper()->query()->retrieve('item_ref_id', $DIC->refinery()->kindlyTo()->int());
        $DIC->ctrl()->setParameter($this, 'item_ref_id', $this->item_ref_id);
    }

    public function executeCommand(): void
    {
        $cmd = $this->ctrl->getCmd();
        $next_class = $this->ctrl->getNextClass();

        switch ($next_class) {
            case strtolower(ilObjLearningSequenceEditConditionGUI::class):
                $this->ctrl->forwardCommand(new $next_class(
                    $this->ctrl,
                    $this->tpl,
                    $this->lng,
                    $this->access,
                    $this->post_wrapper,
                    $this->ui_factory,
                    $this->ui_renderer,
                    $this->request,
                ));
                break;
            default:
                switch ($cmd) {
                    case self::CMD_MANAGE_CONDITIONS:
                        $this->$cmd();
                        break;
                    default:
                        throw new ilException("ilObjLearningSequenceConditionsGUI: Command not supported: $cmd");
                }
                break;
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

        $this->tpl->setContent(
            $this->ui_renderer->render([
                $this->buildConditionsTable(),
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

        $icon = $this->ui_factory->symbol()->icon()->custom('', '');
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

    /**
     * @throws ilCtrlException
     */
    private function buildConditionsTable(): Table
    {
        global $DIC;
        $df = new Factory();

        // single action - edit
        $url = ilObjLearningSequenceEditConditionGUI::getUrl(3);
        $url_builder = new URLBuilder($df->uri(ILIAS_HTTP_PATH . '/' . $url));
        [$url_builder, $action_parameter_token, $row_id_token] = $url_builder->acquireParameters(
            ['condition'],
            'edit',
            'id'
        );
        $actions['edit'] = $this->ui_factory->table()->action()->single(
            $this->lng->txt('edit'),
            $url_builder,
            $row_id_token,
        );

        // standard action - delete
        $url = ilObjLearningSequenceEditConditionGUI::getUrl(3);
        $url_builder = new URLBuilder($df->uri(ILIAS_HTTP_PATH . '/' . $url));
        [$url_builder, $action_parameter_token, $row_id_token] = $url_builder->acquireParameters(
            ['condition'],
            'delete',
            'id'
        );
        $actions['delete'] = $this->ui_factory->table()->action()->standard(
            $this->lng->txt('delete'),
            $url_builder,
            $row_id_token,
        );

        return $this->ui_factory->table()->data(
            new ilLearningSequenceConditionsRetrieval(),
            'Conditions',
            [
                'id' => $this->ui_factory->table()->column()->text('ID'),
                'type' => $this->ui_factory->table()->column()->text('Type'),
                'name' => $this->ui_factory->table()->column()->text('Name'),
            ]
        )
            ->withActions($actions)
            ->withRequest($this->request);
    }
}
