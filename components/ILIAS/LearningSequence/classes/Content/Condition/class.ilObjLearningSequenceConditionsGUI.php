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

use ILIAS\Data\Factory;
use ILIAS\HTTP\Wrapper\ArrayBasedRequestWrapper;
use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover;
use ILIAS\UI\Component\Menu\Drilldown;
use ILIAS\UI\Component\Modal\Roundtrip;
use ILIAS\UI\Component\Table\Table;
use ILIAS\UI\URLBuilder;
use JetBrains\PhpStorm\NoReturn;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @ilCtrl_isCalledBy ilObjLearningSequenceConditionsGUI: ilObjLearningSequenceContentGUI
 */
class ilObjLearningSequenceConditionsGUI
{
    public const string CMD_MANAGE_CONDITIONS = "manageConditions";
    public const string CMD_CONFIRM_DELETE_CONDITION = "confirmDeleteCondition";
    public const string CMD_CREATE_CONDITION = "createCondition";
    public const string CMD_DELETE_CONDITION = "deleteCondition";

    protected int $lso_ref_id;
    /** @var int LSO content object */
    protected int $item_ref_id;
    private ilObjLearningSequenceConditionDiscover $discoverer;
    private ArrayBasedRequestWrapper $query;
    private \ILIAS\Refinery\Factory $refinery;

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
    ) {
        global $DIC;
        $this->lso_ref_id = $DIC->http()->wrapper()->query()->retrieve('ref_id', $DIC->refinery()->kindlyTo()->int());
        $this->item_ref_id = $DIC->http()->wrapper()->query()->retrieve('item_ref_id', $DIC->refinery()->kindlyTo()->int());
        $this->discoverer = new ilObjLearningSequenceConditionDiscover();
        $this->query = $DIC->http()->wrapper()->query();
        $this->refinery = $DIC->refinery();
    }

    /**
     * @throws ilException
     * @throws ilCtrlException
     */
    public function executeCommand(): void
    {
        $cmd = $this->ctrl->getCmd();
        $next_class = $this->ctrl->getNextClass();

        switch ($next_class) {
            case strtolower(ilObjLearningSequenceConditionConfigurationGUI::class):
                $this->ctrl->setReturn($this, self::CMD_MANAGE_CONDITIONS);
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
                    case self::CMD_CONFIRM_DELETE_CONDITION:
                    case self::CMD_CREATE_CONDITION:
                    case self::CMD_DELETE_CONDITION:
                        $this->$cmd();
                        break;
                    default:
                        throw new ilException("ilObjLearningSequenceConditionsGUI: Command not supported: $cmd");
                }
                break;
        }
    }

    /**
     * @return void
     * @throws ilCtrlException
     */
    protected function manageConditions(): void
    {
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

    /**
     * @return Drilldown
     */
    protected function buildDrilldown(): Drilldown
    {
        $input_conditions_steps = array_map(
            function (string $class): array {
                $condition = new $class();
                $condition->setObjRefId($this->item_ref_id);
                $condition->setLsoRefId($this->lso_ref_id);
                return $condition->setupSteps();
            },
            $this->discoverer->getAllInputConditions()
        );

        $output_conditions_steps = array_map(
            function (string $class): array {
                $condition = new $class();
                $condition->setObjRefId($this->item_ref_id);
                $condition->setLsoRefId($this->lso_ref_id);
                return $condition->setupSteps();
            },
            $this->discoverer->getAllOutputConditions()
        );

        return $this->ui_factory->menu()->drilldown(
            'Manage Conditions',
            [
                $this->ui_factory->menu()->sub('Input Conditions', array_merge(...$input_conditions_steps)),
                $this->ui_factory->menu()->sub('Output Conditions', array_merge(...$output_conditions_steps))
            ]
        );
    }

    /**
     * @return Roundtrip
     */
    protected function buildAddConditionModal(): Roundtrip
    {
        return $this->ui_factory->modal()->roundtrip(
            'Add condition',
            [
                'drilldown' => $this->buildDrilldown()
            ]
        );
    }

    /**
     * @throws ilCtrlException
     * @throws ilException
     */
    private function buildConditionsTable(): Table
    {
        $df = new Factory();
        $af = new \ILIAS\UI\Implementation\Component\Table\Action\Factory();

        // single action - edit
        $this->ctrl->setParameterByClass(ilObjLearningSequenceConditionConfigurationGUI::class, 'ref_id', $this->lso_ref_id);
        $this->ctrl->setParameterByClass(ilObjLearningSequenceConditionConfigurationGUI::class, 'item_ref_id', $this->item_ref_id);
        $url = $this->ctrl->getLinkTargetByClass(
            ilObjLearningSequenceConditionConfigurationGUI::class,
            ilObjLearningSequenceConditionConfigurationGUI::CMD_CONFIGURE_COMMAND
        );
        $url_builder = new URLBuilder($df->uri(ILIAS_HTTP_PATH . '/' . $url));
        [$url_builder, $action_parameter_token, $row_id_token] = $url_builder->acquireParameters(
            ['condition'],
            'cmd',
            'id'
        );

        $actions = [];
        foreach ($this->discoverer->getAllConditionIdsForItem($this->item_ref_id) as $condition_id) {
            $condition = $this->discoverer->getConditionInstanceById($condition_id);
            if ($condition->getAdditionalForm() === null) {
                continue;
            }
            if (method_exists($condition, 'getSubtype')) {
                $subtype = $condition->getSubtype();
                $url_builder = $url_builder->withParameter($action_parameter_token, 'subtype=' . $subtype);
            }

            $actions['condition_' . $condition_id] = $af->single(
                $this->lng->txt('edit'),
                $url_builder,
                $row_id_token
            );
        }

        // standard action: delete condition
        $this->ctrl->setParameter($this, 'item_ref_id', $this->item_ref_id);
        $url = $this->ctrl->getLinkTarget($this, self::CMD_CONFIRM_DELETE_CONDITION);
        $url_builder = new URLBuilder($df->uri(ILIAS_HTTP_PATH . '/' . $url));
        [$url_builder, $action_parameter_token, $row_id_token] = $url_builder->acquireParameters(
            ['delete'],
            'delete',
            'ids'
        );
        $actions['delete'] = $this->ui_factory->table()->action()->standard(
            $this->lng->txt('delete'),
            $url_builder,
            $row_id_token,
        )->withAsync();

        return $this->ui_factory->table()->data(
            new ilLearningSequenceConditionsRetrieval($this->lso_ref_id, $this->item_ref_id),
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

    /**
     * Creates a new condition.
     *
     * @return void
     * @throws ilCtrlException|ilException
     */
    private function createCondition(): void
    {
        $type_id = (int) $this->request->getQueryParams()['type_id'] ?? '';
        $subtype = $this->request->getQueryParams()['subtype'] ?? null;

        try {
            $condition = $this->discoverer->getConditionInstanceByTypeId($type_id, $this->lso_ref_id, $this->item_ref_id, $subtype);
            $condition->create();
        } catch (LogicException $e) {

            // todo lang

            $this->tpl->setOnScreenMessage('failure', 'Condition already exists', true);
            $this->ctrl->setParameterByClass(\ilObjLearningSequenceConditionsGUI::class, 'ref_id', $this->lso_ref_id);
            $this->ctrl->setParameterByClass(\ilObjLearningSequenceConditionsGUI::class, 'item_ref_id', $this->item_ref_id);
            $this->ctrl->redirectByClass(
                [
                    ilRepositoryGUI::class,
                    ilObjLearningSequenceGUI::class,
                    ilObjLearningSequenceContentGUI::class,
                    \ilObjLearningSequenceConditionsGUI::class
                ],
                ilObjLearningSequenceConditionsGUI::CMD_MANAGE_CONDITIONS
            );
        }

        $this->tpl->setOnScreenMessage('success', $this->lng->txt('saved_successfully'), true);
        $this->ctrl->setParameter($this, 'item_ref_id', $this->item_ref_id);
        $this->ctrl->redirectByClass(
            [
                ilRepositoryGUI::class,
                ilObjLearningSequenceGUI::class,
                ilObjLearningSequenceContentGUI::class,
                \ilObjLearningSequenceConditionsGUI::class
            ],
            ilObjLearningSequenceConditionsGUI::CMD_MANAGE_CONDITIONS
        );
    }

    /**
     * @throws ilCtrlException
     * @throws ilException
     */
    #[NoReturn]
    private function confirmDeleteCondition(): void
    {
        $this->ctrl->setParameter($this, 'item_ref_id', $this->item_ref_id);

        $string_list = $this->refinery->kindlyTo()->listOf($this->refinery->kindlyTo()->string());

        // todo lang
        if (!$this->query->has('delete_ids')) {
            $modal = $this->ui_factory->modal()->interruptive(
                $this->lng->txt('delete'),
                'Bitte wählen Sie Bedingungen, die gelöscht werden sollen.',
                '#'
            )->withActionButtonLabel($this->lng->txt('ok'));
            exit($this->ui_renderer->render($modal));
        }

        $condition_ids = $this->query->retrieve('delete_ids', $string_list);

        $to_delete = $condition_ids[0] ?? null;

        $conditions_to_delete = [];

        // all items
        if ($to_delete === 'ALL_OBJECTS') {
            $conditions = $this->discoverer->getAllConditionIdsForItem($this->item_ref_id);
            foreach ($conditions as $condition_id) {
                $conditions_to_delete[] = $condition_id;
            }
        } elseif (is_numeric($to_delete)) {
            // single item
            $conditions_to_delete[] = (int) $to_delete;
        } else {
            // selected items
            foreach ($to_delete as $id) {
                $conditions_to_delete[] = (int) $id;
            }
        }

        $affected_items = [];
        foreach ($conditions_to_delete as $condition_id) {
            $affected_items[] = $this->ui_factory->modal()->interruptiveItem()->standard(
                'condition_' . $condition_id,
                $this->discoverer->getConditionInstanceById($condition_id)->getName(),
            );
        }


        // todo lang
        $modal = $this->ui_factory->modal()->interruptive(
            $this->lng->txt('delete'),
            'Möchten Sie diese Bedingungen wirklich löschen?',
            $this->ctrl->getLinkTargetByClass(self::class, self::CMD_DELETE_CONDITION)
        )->withAffectedItems($affected_items);

        exit($this->ui_renderer->render($modal));
    }

    /**
     * Deletes a condition
     *
     * @return void
     * @throws ilException
     */
    private function deleteCondition(): void
    {
        if (!$this->post_wrapper->has('interruptive_items')) {
            throw new ilException('No condition id provided');
        }

        $list = $this->refinery->kindlyTo()->listOf($this->refinery->kindlyTo()->string());
        $condition_to_delete = $this->post_wrapper->retrieve('interruptive_items', $list);

        foreach ($condition_to_delete as $condition) {
            $condition_id = (int) str_replace('condition_', '', $condition);
            $instance = $this->discoverer->getConditionInstanceById($condition_id);
            $instance->delete();
        }

        // todo lang
        $this->tpl->setOnScreenMessage('success', $this->lng->txt('conditions_deleted'), true);
        $this->ctrl->setParameter($this, 'item_ref_id', $this->item_ref_id);
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONDITIONS);
    }
}
