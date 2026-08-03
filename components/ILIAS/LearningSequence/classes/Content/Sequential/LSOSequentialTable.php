<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Sequential;

use ILIAS\UI\Component\Table\OrderingRetrieval;
use ILIAS\UI\Component\Table\OrderingRowBuilder;
use ILIAS\UI\Factory as UIFactory;
use ILIAS\UI\Renderer as UIRenderer;
use Generator;
use ilObjLearningSequenceContentGUI;

/**
 * Class LSOSequentialTable
 */
class LSOSequentialTable implements OrderingRetrieval
{
    public function __construct(
        protected ilObjLearningSequenceContentGUI $parent_gui,
        protected UIFactory $ui_factory,
        protected UIRenderer $ui_renderer,
        protected \ilLanguage $lng,
        protected \ilCtrl $ctrl,
        protected array $items,
        protected string $target_url,
        protected string $title,
        protected \Psr\Http\Message\ServerRequestInterface $request,
        protected \ilGlobalTemplateInterface $tpl,
        protected int $ref_id,
        protected int $obj_id,
        protected ?array $filter_data = null
    ) {
    }

    public function getRows(
        OrderingRowBuilder $row_builder,
        array $visible_column_ids
    ): Generator {
        foreach ($this->getSortedItems() as $item) {
            $ref_id = $item->getRefId();
            $obj_id = \ilObject::_lookupObjId($ref_id);
            $type = \ilObject::_lookupType($obj_id);
            $title = \ilObject::_lookupTitle($obj_id);

            $current_op = $item->getPostCondition()->getConditionOperator();
            $condition_label = $current_op;
            if ($current_op === \ilLSPostCondition::OPERATOR_ALWAYS) {
                $condition_label = $this->lng->txt('condition_always');
            } elseif ($current_op === \ilLSPostCondition::OPERATOR_LP) {
                $condition_label = 'Gemäß Lernfortschritt'; // #ToDo Sprachvariable
            }

            $lso = \ilObjLearningSequence::getInstanceByRefId($this->ref_id);
            $ref_id_lso = $lso->getRefId();
            $obj_id_lso = $lso->getId();

            $actions = $this->parent_gui->getTableActionHandler()->collectActions(
                $ref_id,
                (new LSOSequentialContent(
                    $this->parent_gui,
                    $this->ui_factory,
                    $this->ui_renderer,
                    $this->lng,
                    $this->ctrl,
                    $this->request,
                    $this->tpl,
                    $ref_id_lso,
                    $obj_id_lso
                ))->getSpecificActions($ref_id, $current_op)
            );

            $record = [
                'ref_id' => $ref_id,
                'title' => $title,
                'type' => $this->ui_factory->symbol()->icon()->standard($type, $type, 'small'),
                'online' => $item->isOnline() ? $this->lng->txt('online') : $this->lng->txt('offline'),
                'condition' => $condition_label
            ];
            $row = $row_builder->buildOrderingRow((string) $ref_id, $record);

            foreach ($actions as $id => $action) {
                if ($action->is_divider) {
                    continue;
                }
                $disabled = false;
                if ($id === 'set_online' && $item->isOnline()) {
                    $disabled = true;
                } elseif ($id === 'set_offline' && !$item->isOnline()) {
                    $disabled = true;
                } elseif ($id === 'condition_always' && $current_op === \ilLSPostCondition::OPERATOR_ALWAYS) {
                    $disabled = true;
                } elseif ($id === 'condition_lp' && $current_op === \ilLSPostCondition::OPERATOR_LP) {
                    $disabled = true;
                }

                if ($disabled) {
                    $row = $row->withDisabledAction((string) $id);
                }
            }

            yield $row;
        }
    }

    public function getColumns(): array
    {
        $df = $this->ui_factory->table()->column();
        return [
            'type' => $df->statusIcon($this->lng->txt('type')),
            'title' => $df->text($this->lng->txt('title')),
            'online' => $df->text($this->lng->txt('online')),
            'condition' => $df->text($this->lng->txt('table_may_proceed'))
        ];
    }

    /**
     * @return \LSItem[] items sorted by their LSO order number (position)
     */
    private function getSortedItems(): array
    {
        $items = $this->getFilteredItems();
        usort($items, fn($a, $b) => $a->getOrderNumber() <=> $b->getOrderNumber());
        return $items;
    }

    /**
     * Applies the filter (name, "user may process" condition, online/offline
     * status) to the items before they are displayed.
     *
     * @return \LSItem[]
     */
    private function getFilteredItems(): array
    {
        if ($this->filter_data === null) {
            return $this->items;
        }

        $name_filter = trim((string) ($this->filter_data['name'] ?? ''));
        $condition_filter = $this->filter_data['condition'] ?? null;
        $online_filter = $this->filter_data['online_status'] ?? null;

        $filtered = [];
        foreach ($this->items as $item) {
            if ($name_filter !== '') {
                $title = \ilObject::_lookupTitle(\ilObject::_lookupObjId($item->getRefId()));
                if (mb_stripos($title, $name_filter) === false) {
                    continue;
                }
            }

            if ($condition_filter !== null && $condition_filter !== '') {
                $current_op = $item->getPostCondition()->getConditionOperator();
                if ($condition_filter === 'always' && $current_op !== \ilLSPostCondition::OPERATOR_ALWAYS) {
                    continue;
                }
                if ($condition_filter === 'lp' && $current_op !== \ilLSPostCondition::OPERATOR_LP) {
                    continue;
                }
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

            $filtered[] = $item;
        }

        return $filtered;
    }

    /**
     * Returns the ref ids in the order submitted by the ordering table.
     *
     * @return int[]
     */
    public function getOrderedRefIds(): array
    {
        $data = $this->buildTable()->getData();
        if (!is_array($data)) {
            return [];
        }
        return array_map('intval', $data);
    }

    public function render(): string
    {
        return $this->ui_renderer->render($this->buildTable());
    }

    private function buildTable(): \ILIAS\UI\Component\Table\Ordering
    {
        $columns = $this->getColumns();

        $data_factory = new \ILIAS\Data\Factory();
        $target_url = $this->target_url;
        if (strpos($target_url, 'http') !== 0) {
            $target_url = \ilUtil::_getHttpPath() . '/' . ltrim($target_url, './');
        }

        $table = $this->ui_factory->table()->ordering(
            $this,
            $data_factory->uri($target_url),
            $this->title,
            $columns
        )->withRequest($this->request);

        $action_factory = $this->ui_factory->table()->action();
        $df = new \ILIAS\Data\Factory();
        $url_builder = new \ILIAS\UI\URLBuilder($df->uri($target_url));
        $query_params_namespace = ['lso', 'content', 'seq'];
        list($url_builder, $action_parameter_token, $row_id_token) = $url_builder->acquireParameters(
            $query_params_namespace,
            "cmd",
            "item_ref_id"
        );

        $lso = \ilObjLearningSequence::getInstanceByRefId($this->ref_id);
        $ref_id = $lso->getRefId();
        $obj_id = $lso->getId();

        $table_actions = [];
        $actions = $this->parent_gui->getTableActionHandler()->collectActions(0, []);
        $specific = (new LSOSequentialContent(
            $this->parent_gui,
            $this->ui_factory,
            $this->ui_renderer,
            $this->lng,
            $this->ctrl,
            $this->request,
            $this->tpl,
            $ref_id,
            $obj_id
        ))->getSpecificActions(0, "");
        foreach ($specific as $id => $action) {
            $actions[$id] = $action;
        }

        foreach ($actions as $id => $action) {
            if ($action->is_divider) {
                continue;
            }
            $command = $action->link !== '' ? $action->link : $id;

            $table_actions[$id] = $action_factory->single(
                $action->label,
                $url_builder->withParameter($action_parameter_token, $command),
                $row_id_token
            );
        }
        $table_actions['delete'] = $action_factory->single(
            $this->lng->txt('delete'),
            $url_builder->withParameter(
                $action_parameter_token,
                ilObjLearningSequenceContentGUI::CMD_CONFIRM_DELETE
            ),
            $row_id_token
        );

        return $table->withActions($table_actions);
    }
}
