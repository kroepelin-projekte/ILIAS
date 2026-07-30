<?php

declare(strict_types=1);

use ILIAS\HTTP\Wrapper\ArrayBasedRequestWrapper;
use ILIAS\LearningSequence\Content\Condition\ConditionHandler;
use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover;
use ILIAS\LearningSequence\Content\Multiselect;
use ILIAS\Refinery\Factory;
use ILIAS\UI\Component\Input\Container\Filter\Standard;

/**
 * Class ilObjLearningSequenceContentGUI
 * @ilCtrl_Calls ilObjLearningSequenceContentGUI: ilObjLearningSequenceConditionsGUI
 */
class ilObjLearningSequenceContentGUI
{
    public const string CMD_MANAGE_CONTENT = "manageContent";
    public const string CMD_SAVE = "save";
    public const string CMD_DELETE = "delete";
    public const string CMD_CONFIRM_DELETE = "confirmDelete";
    public const string CMD_CANCEL = "cancel";
    public const string CMD_SET_ONLINE = "setOnline";
    public const string CMD_SET_OFFLINE = "setOffline";
    public const string CMD_SET_START_OBJECT = "setStartObject";
    public const string CMD_UNSET_START_OBJECT = "unsetStartObject";
    public const string CMD_SET_END_OBJECT = "setEndObject";
    public const string CMD_UNSET_END_OBJECT = "unsetEndObject";

    public const string FIELD_ORDER = 'f_order';
    public const string FIELD_ONLINE = 'f_online';
    public const string FIELD_POSTCONDITION_TYPE = 'f_pct';

    public function __construct(
        protected \ilObjLearningSequenceGUI $parent_gui,
        protected \ilCtrl $ctrl,
        protected \ilGlobalTemplateInterface $tpl,
        protected \ilLanguage $lng,
        protected \ilAccessHandler $access,
        protected \ilConfirmationGUI $confirmation_gui,
        protected \LSItemOnlineStatus $ls_item_online_status,
        protected \ILIAS\HTTP\Wrapper\RequestWrapper $post_wrapper,
        protected \ILIAS\Refinery\Factory $refinery,
        protected \ILIAS\UI\Factory $ui_factory,
        protected \ILIAS\UI\Renderer $ui_renderer,
        protected \Psr\Http\Message\ServerRequestInterface $request
    ) {
    }

    public function executeCommand(): void
    {
        $next_class = $this->ctrl->getNextClass($this);

        if (!$this->access->checkAccess("read", '', $this->parent_gui->getRefId())) {
            $this->tpl->setOnScreenMessage('info', $this->lng->txt('msg_no_perm_read_item'), true);

            $this->ctrl->redirect($this->parent_gui, 'view');
        }

        switch ($next_class) {
            case strtolower(ilObjLearningSequenceConditionsGUI::class):
                $gui = new ilObjLearningSequenceConditionsGUI(
                    $this,
                    $this->parent_gui,
                    $this->ctrl,
                    $this->tpl,
                    $this->lng,
                    $this->access,
                    $this->post_wrapper,
                    $this->ui_factory,
                    $this->ui_renderer,
                );
                $this->ctrl->forwardCommand($gui);
                break;

            default:
                $cmd = $this->ctrl->getCmd();

                switch ($cmd) {
                    case self::CMD_CANCEL:
                        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
                        break;
                    case 'view':
                        $cmd = self::CMD_MANAGE_CONTENT;
                        // no break
                    case self::CMD_MANAGE_CONTENT:
                    case self::CMD_SAVE:
                    case self::CMD_DELETE:
                    case self::CMD_CONFIRM_DELETE:
                    case self::CMD_SET_ONLINE:
                    case self::CMD_SET_OFFLINE:
                    case self::CMD_SET_START_OBJECT:
                    case self::CMD_UNSET_START_OBJECT:
                    case self::CMD_SET_END_OBJECT:
                    case self::CMD_UNSET_END_OBJECT:
                        $this->$cmd();
                        break;
                    default:
                        throw new ilException("ilObjLearningSequenceContentGUI: command not supported: $cmd");
                }
                break;
        }
    }

    protected function manageContent(): void
    {
        $this->parent_gui->showPossibleSubObjects();

        $filter_builder = new \ilObjLearningSequenceContentFilter(
            $this->ui_factory,
            $this->lng,
            $this->ctrl,
            $this->parent_gui
        );

        $filter = $filter_builder->getFilter(
            $this->ctrl->getLinkTarget($this, self::CMD_MANAGE_CONTENT),
            $this->getInputConditionOptions(),
            $this->getOutputConditionOptions()
        );

        try {
            $filter = $filter->withRequest($this->request);
            $filter_data = $filter->getData();
        } catch (\InvalidArgumentException $e) {
            $filter_data = [];
        }

        $items = $this->parent_gui->getObject()->getLSItems();
        $boundaries_db = new \ilObjLearningSequenceContentBoundaries($GLOBALS['DIC']->database());
        $boundaries = $boundaries_db->getBoundariesFor($this->parent_gui->getObject()->getId());

        $messages = [];
        if ($boundaries['start_ref_id'] === 0) {
            $messages[] = "Um die Lernsequenz zu starten, wird ein Start Objekt benötigt"; # ToDo Sprachvariable
        }
        if ($boundaries['end_ref_id'] === 0) {
            $messages[] = "Um die Lernsequenz zu starten, wird ein End Objekt benötigt"; # ToDo Sprachvariable
        }

        if (count($messages) > 0) {
            $this->tpl->setOnScreenMessage('info', implode("<br>", $messages));
        }

        $data = $this->buildData($items, $filter_data);
        $this->renderTable($data, $filter);
    }

    protected function buildData(array $items, ?array $filter_data = null): array
    {
        $data = [];

        $name_filter = $filter_data['name'] ?? '';
        $input_filter = $filter_data['input_conditions'] ?? [];
        $output_filter = $filter_data['output_conditions'] ?? [];

        $boundaries_db = new \ilObjLearningSequenceContentBoundaries($GLOBALS['DIC']->database());
        $boundaries = $boundaries_db->getBoundariesFor($this->parent_gui->getObject()->getId());
        $start_ref_id = $boundaries['start_ref_id'];
        $end_ref_id = $boundaries['end_ref_id'];

        usort($items, function ($a, $b) use ($start_ref_id, $end_ref_id) {
            if ($a->getRefId() === $start_ref_id) {
                return -1;
            }
            if ($b->getRefId() === $start_ref_id) {
                return 1;
            }
            if ($a->getRefId() === $end_ref_id) {
                return 1;
            }
            if ($b->getRefId() === $end_ref_id) {
                return -1;
            }
            return $a->getOrderNumber() <=> $b->getOrderNumber();
        });

        $condition_handler = new ConditionHandler();
        $lso_ref_id = $this->parent_gui->getObject()->getRefId();

        foreach ($items as $index => $item) {
            $ref_id = $item->getRefId();
            $obj_id = \ilObject::_lookupObjId($ref_id);
            $title = \ilObject::_lookupTitle($obj_id);

            if ($name_filter !== '' && mb_stripos($title, $name_filter) === false) {
                continue;
            }

            $type = $item->getType();
            $actions = $this->collectActions($ref_id, $obj_id, $type, $item->isOnline(), $start_ref_id, $end_ref_id);

            $input_conditions = [];
            $db_input_conditions = $condition_handler->getInputConditionsByRefId($lso_ref_id, $ref_id);
            foreach ($db_input_conditions as $db_cond) {
                $input_conditions[] = new \ilObjLearningSequenceConditionData(
                    title: $db_cond['title'],
                    value: $db_cond['value'],
                    internal_name: $db_cond['internal_name']
                );
            }

            $output_conditions = [];
            $db_output_conditions = $condition_handler->getOutputConditionsByRefId($lso_ref_id, $ref_id);
            foreach ($db_output_conditions as $db_cond) {
                $output_conditions[] = new \ilObjLearningSequenceConditionData(
                    title: $db_cond['title'],
                    value: $db_cond['value'],
                    internal_name: $db_cond['internal_name']
                );
            }

            if (count($input_filter) > 0) {
                $found = false;
                foreach ($input_conditions as $ic) {
                    if (in_array($ic->internal_name, $input_filter)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue;
                }
            }

            if (count($output_filter) > 0) {
                $found = false;
                foreach ($output_conditions as $oc) {
                    if (in_array($oc->internal_name, $output_filter)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue;
                }
            }

            // Information
            $prev_title = "(keines)"; #Todo Sprachvariable
            if ($index > 0) {
                $prev_title = \ilObject::_lookupTitle(\ilObject::_lookupObjId($items[$index - 1]->getRefId()));
            }
            $next_title = "(keines)"; #Todo Sprachvariable
            if ($index < count($items) - 1) {
                $next_title = \ilObject::_lookupTitle(\ilObject::_lookupObjId($items[$index + 1]->getRefId()));
            }

            $data[] = new \ilObjLearningSequenceContentData(
                obj_id: $obj_id,
                title: \ilObject::_lookupTitle($obj_id),
                description: \ilObject::_lookupDescription($obj_id),
                type: $type,
                icon_path: \ilObject::_getIcon($obj_id, "big", $type),
                href: \ilLink::_getLink($ref_id, $type),
                is_online: $item->isOnline(),
                start_object: ($ref_id === $start_ref_id) ? "Start" : "",
                end_object: ($ref_id === $end_ref_id) ? "End" : "",
                previous_objects: $prev_title,
                next_objects: $next_title,
                input_conditions: $input_conditions,
                output_conditions: $output_conditions,
                actions: $actions
            );
        }

        return $data;
    }

    protected function collectActions(int $ref_id, int $obj_id, string $type, bool $is_online, int $start_ref_id = 0, int $end_ref_id = 0): array
    {
        $actions = [];
        $standard_actions = [];

        $list_gui = \ilObjectListGUIFactory::_getListGUIByType($type);
        $list_gui->initItem($ref_id, $obj_id, $type);
        $list_gui->setContainerObject($this->parent_gui);

        $list_gui->enableCut(true);
        $list_gui->enableDelete(true);
        $list_gui->enableLink(true);
        $list_gui->enableCopy(true);

        if (method_exists($list_gui, 'enableDownload')) {
            $list_gui->enableDownload(true);
        }

        $standard_commands = $list_gui->getCommands();
        $allowed_commands = ['settings', 'delete', 'download', 'link', 'move', 'copy', 'cut', 'info'];
        foreach ($standard_commands as $cmd) {
            $lang_var = $cmd['lang_var'];
            $cmd_name = $cmd['cmd'];
            $key = ($lang_var !== '') ? $lang_var : $cmd_name;

            if (!in_array($key, $allowed_commands)) {
                continue;
            }

            $label = ($lang_var !== '') ? $this->lng->txt($lang_var) : $cmd_name;

            $standard_actions[$key] = new \ilObjLearningSequenceActionData(
                label: $label,
                link: $cmd['link']
            );
        }
        $manual_cmds = [
            'delete' => ['permission' => 'delete', 'lang_var' => 'delete'],
            'cut' => ['permission' => 'delete', 'lang_var' => 'move'],
            'copy' => ['permission' => 'copy', 'lang_var' => 'copy'],
            'link' => ['permission' => 'delete', 'lang_var' => 'link'],
        ];

        foreach ($manual_cmds as $cmd_name => $info) {
            if (!isset($standard_actions[$cmd_name])) {
                if ($this->access->checkAccess($info['permission'], '', $ref_id, $type)) {
                    $this->ctrl->setParameter($this->parent_gui, 'item_ref_id', $ref_id);
                    $link = $this->ctrl->getLinkTarget($this->parent_gui, $cmd_name);

                    $label = $this->lng->txt($info['lang_var']);

                    $standard_actions[$cmd_name] = new \ilObjLearningSequenceActionData(
                        label: $label,
                        link: $link
                    );
                }
            }
        }

        if (isset($standard_actions['settings'])) {
            $actions[] = $standard_actions['settings'];
            $actions[] = \ilObjLearningSequenceActionData::divider();
        }

        $this->ctrl->setParameterByClass(ilObjLearningSequenceConditionsGUI::class, 'item_ref_id', $ref_id);
        $actions[] = new \ilObjLearningSequenceActionData(label: 'Conditions', link: $this->ctrl->getLinkTargetByClass(ilObjLearningSequenceConditionsGUI::class, ilObjLearningSequenceConditionsGUI::CMD_MANAGE_CONDITIONS));
        $actions[] = \ilObjLearningSequenceActionData::divider();

        if ($this->ls_item_online_status->hasChangeableOnlineStatus($ref_id)) {
            $this->ctrl->setParameter($this, 'item_ref_id', $ref_id);
            if ($is_online) {
                $label = $this->lng->txt('set_offline');
                $link = $this->ctrl->getLinkTarget($this, self::CMD_SET_OFFLINE);
            } else {
                $label = $this->lng->txt('set_online');
                $link = $this->ctrl->getLinkTarget($this, self::CMD_SET_ONLINE);
            }
            $actions[] = new \ilObjLearningSequenceActionData(label: $label, link: $link);
        }

        $this->ctrl->setParameter($this, 'item_ref_id', $ref_id);
        if ($ref_id === $start_ref_id) {
            $actions[] = new \ilObjLearningSequenceActionData(label: 'Unset start object', link: $this->ctrl->getLinkTarget($this, self::CMD_UNSET_START_OBJECT));
        } else {
            $actions[] = new \ilObjLearningSequenceActionData(label: 'Set start object', link: $this->ctrl->getLinkTarget($this, self::CMD_SET_START_OBJECT));
        }

        if ($ref_id === $end_ref_id) {
            $actions[] = new \ilObjLearningSequenceActionData(label: 'Unset end object', link: $this->ctrl->getLinkTarget($this, self::CMD_UNSET_END_OBJECT));
        } else {
            $actions[] = new \ilObjLearningSequenceActionData(label: 'Set end object', link: $this->ctrl->getLinkTarget($this, self::CMD_SET_END_OBJECT));
        }

        $actions[] = \ilObjLearningSequenceActionData::divider();

        $remaining_order = ['download', 'delete', 'link', 'cut', 'copy'];
        foreach ($remaining_order as $key) {
            if (isset($standard_actions[$key]) && $key !== 'settings') {
                $actions[] = $standard_actions[$key];
            }
        }

        return $actions;
    }

    protected function renderTable(array $data, Standard $filter): void
    {
        $this->lng->loadLanguageModule('trac');
        $this->tpl->addCss("assets/css/alp_content_management_presentation.css");

        $table = new ilObjLearningSequenceContentTable(
            $this->ui_factory,
            $this->ui_renderer,
            $data,
            $filter
        );

        $html = $table->render();

        // SELECT
        $picker_factory = new Multiselect($this->ui_factory, $this->lng);
        $items = $this->parent_gui->getObject()->getLSItems();
        $multi_picker = $picker_factory->getPicker("LSO Objekt Auswahl (Multi)", true, $items);
        $single_picker = $picker_factory->getPicker("LSO Objekt Auswahl (Single)", false, $items);

        $html .= $this->ui_renderer->render([
            $multi_picker,
            $single_picker
        ]);

        $this->tpl->setContent($html);
    }

    protected function getInputConditionOptions(): array
    {
        $discoverer = new ilObjLearningSequenceConditionDiscover();
        $classes = $discoverer->getAllInputConditions();
        $options = [];

        foreach ($classes as $class) {
            $options[$discoverer->getConditionNameByClass($class)] = $discoverer->getConditionTitleByClass($class);
        }

        return $options;
    }

    protected function getOutputConditionOptions(): array
    {
        $discoverer = new ilObjLearningSequenceConditionDiscover();
        $classes = $discoverer->getAllOutputConditions();
        $options = [];

        foreach ($classes as $class) {
            $options[$discoverer->getConditionNameByClass($class)] = $discoverer->getConditionTitleByClass($class);
        }

        return $options;
    }

    protected function confirmDelete(): void
    {
        $this->parent_gui->deleteObject();
    }

    protected function delete(): void
    {
        $ref_ids = $this->post_wrapper->retrieve(
            "id",
            $this->refinery->kindlyTo()->listOf($this->refinery->kindlyTo()->int())
        );

        $this->parent_gui->getObject()->deletePostConditionsForSubObjects($ref_ids);

        $this->tpl->setOnScreenMessage("success", $this->lng->txt('entries_deleted'), true);
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
    }

    public function getPossiblePostConditionsForType(string $type): array
    {
        return $this->parent_gui->getObject()->getPossiblePostConditionsForType($type);
    }

    public function getFieldName(string $field_name, int $ref_id): string
    {
        return implode('_', [$field_name, (string) $ref_id]);
    }

    protected function save(): void
    {
        $data = $this->parent_gui->getObject()->getLSItems();
        $r = $this->refinery;

        $updated = [];
        foreach ($data as $lsitem) {
            $ref_id = $lsitem->getRefId();
            $online = $this->getFieldName(self::FIELD_ONLINE, $ref_id);
            $order = $this->getFieldName(self::FIELD_ORDER, $ref_id);
            $condition_type = $this->getFieldName(self::FIELD_POSTCONDITION_TYPE, $ref_id);

            $condition_type = $this->post_wrapper->retrieve($condition_type, $r->kindlyTo()->string());
            $online = $this->post_wrapper->retrieve($online, $r->byTrying([$r->kindlyTo()->bool(), $r->always(false)]));
            $order = $this->post_wrapper->retrieve(
                $order,
                $r->in()->series([
                    $r->kindlyTo()->string(),
                    $r->custom()->transformation(fn($v) => ltrim($v, '0')),
                    $r->kindlyTo()->int()
                ])
            );

            $condition = $lsitem->getPostCondition()
                ->withConditionOperator($condition_type);
            $updated[] = $lsitem
                ->withOnline($online)
                ->withOrderNumber($order)
                ->withPostCondition($condition);
        }

        $this->parent_gui->getObject()->storeLSItems($updated);
        $this->tpl->setOnScreenMessage("success", $this->lng->txt('entries_updated'), true);
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
    }

    protected function setOnline(): void
    {
        $this->updateOnlineStatus(true);
    }

    protected function setOffline(): void
    {
        $this->updateOnlineStatus(false);
    }

    protected function updateOnlineStatus(bool $status): void
    {
        $item_ref_id = (int) ($this->request->getQueryParams()['item_ref_id'] ?? 0);
        if ($item_ref_id > 0) {
            $this->ls_item_online_status->setOnlineStatus($item_ref_id, $status);
            $items = $this->parent_gui->getObject()->getLSItems();
            $updated = [];
            foreach ($items as $item) {
                if ($item->getRefId() === $item_ref_id) {
                    $item = $item->withOnline($status);
                }
                $updated[] = $item;
            }
            $this->parent_gui->getObject()->storeLSItems($updated);

            $msg = $status ? $this->lng->txt('msg_obj_online') : $this->lng->txt('msg_obj_offline');
            $this->tpl->setOnScreenMessage('success', $msg, true);
        }
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
    }

    protected function setStartObject(): void
    {
        $item_ref_id = (int) ($this->request->getQueryParams()['item_ref_id'] ?? 0);
        if ($item_ref_id > 0) {
            $db = new \ilObjLearningSequenceContentBoundaries($GLOBALS['DIC']->database());
            $boundaries = $db->getBoundariesFor($this->parent_gui->getObject()->getId());

            if ($boundaries['end_ref_id'] === $item_ref_id) {
                $this->tpl->setOnScreenMessage('failure', 'An object cannot be start and end object at the same time.', true); # ToDo Sprachvariable
                $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
                return;
            }

            $db->setStartRefId($this->parent_gui->getObject()->getId(), $item_ref_id);
            $this->tpl->setOnScreenMessage('success', 'Start object set.', true); # ToDo Sprachvariable
        }
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
    }

    protected function unsetStartObject(): void
    {
        $db = new \ilObjLearningSequenceContentBoundaries($GLOBALS['DIC']->database());
        $db->unsetStartRefId($this->parent_gui->getObject()->getId());
        $this->tpl->setOnScreenMessage('success', 'Start object unset.', true); # ToDo Sprachvariable
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
    }

    protected function setEndObject(): void
    {
        $item_ref_id = (int) ($this->request->getQueryParams()['item_ref_id'] ?? 0);
        if ($item_ref_id > 0) {
            $db = new \ilObjLearningSequenceContentBoundaries($GLOBALS['DIC']->database());
            $boundaries = $db->getBoundariesFor($this->parent_gui->getObject()->getId());

            if ($boundaries['start_ref_id'] === $item_ref_id) {
                $this->tpl->setOnScreenMessage('failure', 'An object cannot be start and end object at the same time.', true); # ToDo Sprachvariable
                $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
                return;
            }

            $db->setEndRefId($this->parent_gui->getObject()->getId(), $item_ref_id);
            $this->tpl->setOnScreenMessage('success', 'End object set.', true); # ToDo Sprachvariable
        }
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
    }

    protected function unsetEndObject(): void
    {
        $db = new \ilObjLearningSequenceContentBoundaries($GLOBALS['DIC']->database());
        $db->unsetEndRefId($this->parent_gui->getObject()->getId());
        $this->tpl->setOnScreenMessage('success', 'End object unset.', true); # ToDo Sprachvariable
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
    }
}
