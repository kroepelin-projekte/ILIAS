<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Adaptive;

require_once __DIR__ . '/LSOAdaptiveMapPrototype.php';

use ilObjLearningSequenceContentGUI;
use ilObjLearningSequenceContentData;
use ILIAS\LearningSequence\Content\Condition\ConditionFactory;
use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover;
use ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveTable;
use ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveFilter;
use ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveBoundaries;
use ILIAS\LearningSequence\Content\LSOContentController;
use ILIAS\LearningSequence\Content\LSOContentDeletion;
use ILIAS\LearningSequence\Player\Map\LSMapViewMode;

/**
 * Class LSOAdaptiveContent
 */
class LSOAdaptiveContent implements LSOContentController
{
    use LSOContentDeletion;
    protected ilObjLearningSequenceContentGUI $parent_gui;
    protected \ILIAS\UI\Factory $ui_factory;
    protected \ILIAS\UI\Renderer $ui_renderer;
    protected \ilLanguage $lng;
    protected \ilCtrl $ctrl;
    protected \Psr\Http\Message\ServerRequestInterface $request;
    protected \ilGlobalTemplateInterface $tpl;
    protected \ilDBInterface $db;
    protected int $ref_id;
    protected int $obj_id;

    public function __construct(
        ilObjLearningSequenceContentGUI $parent_gui,
        \ILIAS\UI\Factory $ui_factory,
        \ILIAS\UI\Renderer $ui_renderer,
        \ilLanguage $lng,
        \ilCtrl $ctrl,
        \Psr\Http\Message\ServerRequestInterface $request,
        \ilGlobalTemplateInterface $tpl,
        int $ref_id,
        int $obj_id
    ) {
        global $DIC;
        $this->parent_gui = $parent_gui;
        $this->ui_factory = $ui_factory;
        $this->ui_renderer = $ui_renderer;
        $this->lng = $lng;
        $this->ctrl = $ctrl;
        $this->request = $request;
        $this->tpl = $tpl;
        $this->ref_id = $ref_id;
        $this->obj_id = $obj_id;
        $this->db = $DIC->database();
    }

    public function getSupportedCommands(): array
    {
        return [
            ilObjLearningSequenceContentGUI::CMD_MANAGE_CONTENT,
            ilObjLearningSequenceContentGUI::CMD_SET_START_OBJECT,
            ilObjLearningSequenceContentGUI::CMD_UNSET_START_OBJECT,
            ilObjLearningSequenceContentGUI::CMD_SET_END_OBJECT,
            ilObjLearningSequenceContentGUI::CMD_UNSET_END_OBJECT,
            ilObjLearningSequenceContentGUI::CMD_DELETE,
        ];
    }

    public function manageContent(): void
    {
        global $DIC;

        // Restore the "add new object" drilldown in the toolbar so that new
        // objects can be created directly from the content management view.
        $this->parent_gui->showPossibleSubObjects();

        $this->tpl->addCss("assets/css/alp_content_management_presentation.css");
        $this->tpl->addCss("assets/css/alp_learning_sequence_map.css");
        $this->tpl->addJavaScript("assets/js/alp_learning_sequence_map.js");

        $lso = \ilObjLearningSequence::getInstanceByRefId($this->ref_id);
        $items = $lso->getLSItems();
        $filter_gui = new LSOAdaptiveFilter($this->ui_factory, $this->lng, $this->ctrl, $this->parent_gui);
        $filter = $filter_gui->getFilter(
            'manageContent',
            $this->getInputConditionOptions(),
            $this->getOutputConditionOptions()
        )->withRequest($this->request);

        $filter_data = $filter->getData();
        $data = $this->getTableData($items, $filter_data);

        $boundaries_db = new LSOAdaptiveBoundaries($this->db);
        $boundary_data = $boundaries_db->getBoundariesFor($this->obj_id);

        $missing_hints = [];
        if ((int) $boundary_data['start_ref_id'] === 0) {
            $missing_hints[] = 'Um die Lernsequenz zu starten, wird ein Start Objekt benötigt.'; // #ToDo Sprachvariable
        }
        if ((int) $boundary_data['end_ref_id'] === 0) {
            $missing_hints[] = 'Um die Lernsequenz zu beenden, wird ein End Objekt benötigt.'; // #ToDo Sprachvariable
        }
        if ($missing_hints !== []) {
            $this->tpl->setOnScreenMessage('info', implode('<br>', $missing_hints));
        }

        $table = new LSOAdaptiveTable(
            $this->ui_factory,
            $this->ui_renderer,
            $data,
            $filter,
            (int) $boundary_data['start_ref_id'],
            (int) $boundary_data['end_ref_id'],
            $this->parent_gui,
            $this->ref_id,
            $this->obj_id,
            $this->lng,
            $this->ctrl,
            $this->request,
            $this->tpl
        );

        $map_builder = $lso->getLocalDI()['map.data_builder'];
        $discoverer = new ilObjLearningSequenceConditionDiscover();
        $condition_factory = new ConditionFactory($discoverer, $DIC->database());
        $map = $map_builder->build(LSMapViewMode::MODE_FULL_ROUTE);
        $prototype = new LSOAdaptiveMapPrototype($map, $condition_factory);

        $this->parent_gui->setContent($table->render() . $prototype->render());
    }

    protected function getTableData(array $items, ?array $filter_data): array
    {
        $boundaries_db = new LSOAdaptiveBoundaries($this->db);
        $boundary_data = $boundaries_db->getBoundariesFor($this->obj_id);
        $start_ref_id = $boundary_data['start_ref_id'];
        $end_ref_id = $boundary_data['end_ref_id'];

        $name_filter = $filter_data['name'] ?? '';
        $input_filter = $filter_data['input_conditions'] ?? [];
        $output_filter = $filter_data['output_conditions'] ?? [];
        $online_filter = $filter_data['online_status'] ?? null;
        $position_filter = $filter_data['position'] ?? [];

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

        $condition_handler = new \ILIAS\LearningSequence\Content\Condition\ConditionHandler();
        $lso_ref_id = $this->ref_id;

        $data = [];
        foreach ($items as $index => $item) {
            $ref_id = $item->getRefId();
            $obj_id = \ilObject::_lookupObjId($ref_id);
            $title = \ilObject::_lookupTitle($obj_id);

            if ($name_filter !== '' && mb_stripos($title, $name_filter) === false) {
                continue;
            }

            if ($online_filter !== null && $online_filter !== '') {
                $is_online = $item->isOnline();
                if ($online_filter === 'online' && !$is_online) {
                    continue;
                }
                if ($online_filter === 'offline' && $is_online) {
                    continue;
                }
            }

            if (count($position_filter) > 0) {
                $is_start = ($ref_id === $start_ref_id);
                $is_end = ($ref_id === $end_ref_id);
                $matches_position =
                    (in_array('start', $position_filter, true) && $is_start)
                    || (in_array('end', $position_filter, true) && $is_end);
                if (!$matches_position) {
                    continue;
                }
            }

            $type = $item->getType();

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

            $icon_path = \ilObject::_getIcon($obj_id, "small", $type);
            $actions = $this->parent_gui->getTableActionHandler()->collectActions(
                $ref_id,
                $this->getSpecificActions(
                    $ref_id,
                    $start_ref_id,
                    $end_ref_id
                ),
                $item->isOnline()
            );

            $row = new ilObjLearningSequenceContentData(
                $ref_id,
                $obj_id,
                $title,
                \ilObject::_lookupDescription($obj_id),
                $type,
                $icon_path,
                \ilLink::_getLink($ref_id, $type),
                $item->isOnline(),
                ($ref_id === $start_ref_id) ? 'Start' : '',
                ($ref_id === $end_ref_id) ? 'End' : '',
                $prev_title,
                $next_title,
                $input_conditions,
                $output_conditions,
                $actions
            );

            $data[] = $row;
        }

        return $data;
    }

    protected function getInputConditionOptions(): array
    {
        return $this->buildConditionOptions(
            $this->getConditionDiscover()->getAllInputConditions()
        );
    }

    protected function getOutputConditionOptions(): array
    {
        return $this->buildConditionOptions(
            $this->getConditionDiscover()->getAllOutputConditions()
        );
    }

    private function getConditionDiscover(): \ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover
    {
        return new \ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover();
    }

    /**
     * Builds the multiselect options for the filter from the discovered
     * condition classes. The option key is the condition's internal name (as
     * used when filtering the rows) and the value is its human readable title.
     *
     * @param string[] $condition_classes
     * @return array<string, string>
     */
    private function buildConditionOptions(array $condition_classes): array
    {
        $discover = $this->getConditionDiscover();
        $options = [];
        foreach ($condition_classes as $class) {
            $name = $discover->getConditionNameByClass($class);
            $options[$name] = $discover->getConditionTitleByClass($class);
        }
        return $options;
    }

    public function getSpecificActions(int $ref_id, int $start_ref_id, int $end_ref_id): array
    {
        $specific_actions = [];

        // 1. Conditions
        $this->ctrl->setParameterByClass(\ilObjLearningSequenceConditionsGUI::class, 'item_ref_id', $ref_id);
        $link = $this->ctrl->getLinkTargetByClass(\ilObjLearningSequenceConditionsGUI::class, 'manageConditions');
        $specific_actions['conditions'] = new \ilObjLearningSequenceActionData(
            label: 'Conditions', #ToDo Sprachvariable hinzufügen
            link: $link
        );

        // 2. Start / End Switcher
        if ($ref_id > 0) {
            if ($ref_id === $start_ref_id) {
                $label = $this->lng->txt('unset_start_object');
                $link = ilObjLearningSequenceContentGUI::CMD_UNSET_START_OBJECT;
                $id = 'unset_start';
            } else {
                $label = $this->lng->txt('set_start_object');
                $link = ilObjLearningSequenceContentGUI::CMD_SET_START_OBJECT;
                $id = 'set_start';
            }
            $this->ctrl->setParameter($this->parent_gui, 'item_ref_id', $ref_id);
            $link = $this->ctrl->getLinkTarget($this->parent_gui, $link);
            $specific_actions[$id] = new \ilObjLearningSequenceActionData(label: $label, link: $link);

            if ($ref_id === $end_ref_id) {
                $label = $this->lng->txt('unset_end_object');
                $link = ilObjLearningSequenceContentGUI::CMD_UNSET_END_OBJECT;
                $id = 'unset_end';
            } else {
                $label = $this->lng->txt('set_end_object');
                $link = ilObjLearningSequenceContentGUI::CMD_SET_END_OBJECT;
                $id = 'set_end';
            }
            $this->ctrl->setParameter($this->parent_gui, 'item_ref_id', $ref_id);
            $link = $this->ctrl->getLinkTarget($this->parent_gui, $link);
            $specific_actions[$id] = new \ilObjLearningSequenceActionData(label: $label, link: $link);
        } else {
            // Template
            $specific_actions['set_start'] = new \ilObjLearningSequenceActionData(
                label: $this->lng->txt('set_start_object'),
                link: ilObjLearningSequenceContentGUI::CMD_SET_START_OBJECT
            );
            $specific_actions['unset_start'] = new \ilObjLearningSequenceActionData(
                label: $this->lng->txt('unset_start_object'),
                link: ilObjLearningSequenceContentGUI::CMD_UNSET_START_OBJECT
            );
            $specific_actions['set_end'] = new \ilObjLearningSequenceActionData(
                label: $this->lng->txt('set_end_object'),
                link: ilObjLearningSequenceContentGUI::CMD_SET_END_OBJECT
            );
            $specific_actions['unset_end'] = new \ilObjLearningSequenceActionData(
                label: $this->lng->txt('unset_end_object'),
                link: ilObjLearningSequenceContentGUI::CMD_UNSET_END_OBJECT
            );
            #Todo sprachvariable
        }

        $this->ctrl->setParameterByClass(\ilObjLearningSequenceConditionsGUI::class, 'item_ref_id', '');
        return $specific_actions;
    }

    public function setStartObject(): void
    {
        global $DIC;
        $ref_id = $this->parent_gui->extractItemRefId();
        if ($ref_id > 0) {
            $boundaries = new \ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveBoundaries($DIC->database());
            $current = $boundaries->getBoundariesFor($this->obj_id);
            if ((int) $current['end_ref_id'] === $ref_id) {
                // An object must never be start and end object at the same time.
                $this->tpl->setOnScreenMessage(
                    'failure',
                    'Ein Objekt kann nicht gleichzeitig Start- und Endobjekt sein.', // #ToDo Sprachvariable
                    true
                );
                $this->ctrl->redirect($this->parent_gui, ilObjLearningSequenceContentGUI::CMD_MANAGE_CONTENT);
            }
            $boundaries->setStartRefId($this->obj_id, $ref_id);
            $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
        }
        $this->ctrl->redirect($this->parent_gui, ilObjLearningSequenceContentGUI::CMD_MANAGE_CONTENT);
    }

    public function unsetStartObject(): void
    {
        global $DIC;
        $boundaries = new \ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveBoundaries($DIC->database());
        $boundaries->unsetStartRefId($this->obj_id);
        $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
        $this->ctrl->redirect($this->parent_gui, ilObjLearningSequenceContentGUI::CMD_MANAGE_CONTENT);
    }

    public function setEndObject(): void
    {
        global $DIC;
        $ref_id = $this->parent_gui->extractItemRefId();
        if ($ref_id > 0) {
            $boundaries = new \ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveBoundaries($DIC->database());
            $current = $boundaries->getBoundariesFor($this->obj_id);
            if ((int) $current['start_ref_id'] === $ref_id) {
                // An object must never be start and end object at the same time.
                $this->tpl->setOnScreenMessage(
                    'failure',
                    'Ein Objekt kann nicht gleichzeitig Start- und Endobjekt sein.', // #ToDo Sprachvariable
                    true
                );
                $this->ctrl->redirect($this->parent_gui, ilObjLearningSequenceContentGUI::CMD_MANAGE_CONTENT);
            }
            $boundaries->setEndRefId($this->obj_id, $ref_id);
            $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
        }
        $this->ctrl->redirect($this->parent_gui, ilObjLearningSequenceContentGUI::CMD_MANAGE_CONTENT);
    }

    public function unsetEndObject(): void
    {
        global $DIC;
        $boundaries = new \ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveBoundaries($DIC->database());
        $boundaries->unsetEndRefId($this->obj_id);
        $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
        $this->ctrl->redirect($this->parent_gui, ilObjLearningSequenceContentGUI::CMD_MANAGE_CONTENT);
    }
}
