<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Adaptive;

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

/**
 * Renders the adaptive learning sequence content table.
 */
readonly class LSOAdaptiveTable
{
    /**
     * Creates the adaptive content table.
     *
     * @param array $objects Content data records.
     */
    public function __construct(
        /** UI factory. */
        private \ILIAS\UI\Factory $ui_factory,
        /** UI renderer. */
        private \ILIAS\UI\Renderer $ui_renderer,
        /** Content data records. */
        private array $objects,
        /** Content filter. */
        private ?\ILIAS\UI\Component\Input\Container\Filter\Standard $filter = null,
        /** Start object reference ID. */
        private int $start_ref_id = 0,
        /** End object reference ID. */
        private int $end_ref_id = 0,
        /** Parent content GUI. */
        private ?\ilObjLearningSequenceContentGUI $parent_gui = null,
        /** Learning sequence repository reference ID. */
        private int $ref_id = 0,
        /** Learning sequence object ID. */
        private int $obj_id = 0,
        /** Language service. */
        private ?\ilLanguage $lng = null,
        /** Controller service. */
        private ?\ilCtrl $ctrl = null,
        /** Server request. */
        private ?\Psr\Http\Message\ServerRequestInterface $request = null,
        /** Global template. */
        private ?\ilGlobalTemplateInterface $tpl = null
    ) {
    }

    /**
     * Renders the content table.
     */
    public function render(): string
    {
        $data = $this->objects;
        $view_controls = [];

        if ($this->filter !== null) {
            $view_controls[] = $this->filter;
        }

        $modals = [];
        $environment = $this->buildEnvironment($modals);

        $mapping = function (
            $row,
            \ilObjLearningSequenceContentData $record,
            $ui_factory,
            array $env
        ) {
            $leading_icon = $ui_factory->symbol()->icon()->custom(
                $record->icon_path,
                $record->type
            );

            $actions = $env['actions']($record);
            $content = $env['content']($record);
            $headline = $env['headline']($record);

            return $row
                ->withHeadline($headline)
                ->withLeadingSymbol($leading_icon)
                ->withSubheadline($record->description)
                ->withContent($content)
                ->withAction($actions);
        };

        $table = $this->ui_factory->table()->presentation(
            'Content Management',
            $view_controls,
            $mapping
        )
            ->withEnvironment($environment)
            ->withData($data);

        $html = $this->ui_renderer->render($table);

        if ($modals !== []) {
            $html .= $this->ui_renderer->render($modals);
        }

        return $html;
    }

    /**
     * Builds the presentation table environment.
     *
     * @param array<int, \ILIAS\UI\Component\Modal\Interruptive> $modals
     * @return array<string, callable>
     */
    private function buildEnvironment(array &$modals): array
    {
        $headline = function (\ilObjLearningSequenceContentData $record): string {
            $link_html = $this->ui_renderer->render(
                $this->ui_factory->link()->standard($record->title, $record->href)
            );

            $badges_html = '';
            if ($record->is_online) {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--online">Online</span>';
            } else {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--offline">Offline</span>';
            }

            if ($record->start_object !== '') {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--start">Start</span>';
            }
            if ($record->end_object !== '') {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--end">End</span>';
            }

            return $link_html . $badges_html;
        };

        $actions = function (\ilObjLearningSequenceContentData $record) use (&$modals) {
            $dropdown_items = [];
            $lso = \ilObjLearningSequence::getInstanceByRefId($this->ref_id);
            $ref_id = $lso->getRefId();
            $obj_id = $lso->getId();

            $specific_actions = (new LSOAdaptiveContent(
                $this->parent_gui,
                $this->ui_factory,
                $this->ui_renderer,
                $this->lng,
                $this->ctrl,
                $this->request,
                $this->tpl,
                $ref_id,
                $obj_id
            ))->getSpecificActions(
                $record->ref_id,
                $this->start_ref_id,
                $this->end_ref_id
            );
            $action_data = $this->parent_gui->getTableActionHandler()->collectActions(
                $record->ref_id,
                $specific_actions,
                $record->is_online
            );

            foreach ($action_data as $id => $action) {
                if ($action->is_divider) {
                    $dropdown_items[] = $this->ui_factory->divider()->horizontal();
                    continue;
                }

                $label = $action->label;
                $link = $action->link;
                if (strpos($link, 'http') !== 0 && strpos($link, 'ilias.php') === false) {
                    $this->ctrl->setParameter($this->parent_gui, 'item_ref_id', $record->ref_id);
                    $link = $this->ctrl->getLinkTarget($this->parent_gui, $link);
                }

                $dropdown_items[] = $this->ui_factory->button()->shy($label, $link);
            }

            $delete_modal = $this->buildDeleteModal($record->ref_id, $record->title);
            $modals[$record->ref_id] = $delete_modal;
            $dropdown_items[] = $this->ui_factory->button()->shy(
                $this->lng->txt('delete'),
                ''
            )->withOnClick($delete_modal->getShowSignal());

            return $this->ui_factory->dropdown()->standard($dropdown_items)->withLabel('');
        };

        $content = function (\ilObjLearningSequenceContentData $record) {
            $input = $record->input_conditions;
            $output = $record->output_conditions;

            $html_conditions = '<div class="alp-cm-conditions">';
            $html_conditions .= '<h4 class="alp-cm-conditions__title">Input conditions</h4>';
            $html_conditions .= $this->renderKeyValueList($input);
            $html_conditions .= '<h4 class="alp-cm-conditions__title alp-cm-conditions__title--spaced">Output conditions</h4>';
            $html_conditions .= $this->renderKeyValueList($output);
            $html_conditions .= '</div>';

            $html_info = '<div class="alp-cm-info">';
            $html_info .= '<h4 class="alp-cm-info__title">Information</h4>';
            $html_info .= '<div class="alp-cm-info__item"><strong>Previous Object</strong> ' . htmlspecialchars($record->previous_objects) . '</div>';
            $html_info .= '<div class="alp-cm-info__item"><strong>Next Object</strong> ' . htmlspecialchars($record->next_objects) . '</div>';
            $html_info .= '</div>';

            return $this->ui_factory->layout()->alignment()->horizontal()->evenlyDistributed(
                $this->ui_factory->legacy()->content($html_conditions),
                $this->ui_factory->legacy()->content($html_info)
            );
        };

        return [
            'headline' => $headline,
            'actions' => $actions,
            'content' => $content,
        ];
    }

    /**
     * Builds the delete confirmation modal for an item.
     */
    private function buildDeleteModal(int $ref_id, string $title): \ILIAS\UI\Component\Modal\Interruptive
    {
        $this->ctrl->setParameter($this->parent_gui, 'item_ref_id', '');
        $form_action = $this->ctrl->getFormAction(
            $this->parent_gui,
            \ilObjLearningSequenceContentGUI::CMD_DELETE
        );

        $item = $this->ui_factory->modal()->interruptiveItem()->keyValue(
            (string) $ref_id,
            $this->lng->txt('title'),
            $title
        );

        return $this->ui_factory->modal()->interruptive(
            $this->lng->txt('delete'),
            $this->lng->txt('info_delete_sure'),
            $form_action
        )
            ->withAffectedItems([$item])
            ->withActionButtonLabel($this->lng->txt('delete'));
    }

    /**
     * Renders a list of condition titles and values.
     *
     * @param ilObjLearningSequenceConditionData[] $conditions
     */
    private function renderKeyValueList(array $conditions): string
    {
        if ($conditions === []) {
            return '<div class="alp-cm-conditions__empty">(keine)</div>';
        }

        $html = '<ul class="alp-cm-kv-list">';
        foreach ($conditions as $condition) {
            $html .= '<li class="alp-cm-kv-list__item">'
                . '<span class="alp-cm-kv-list__condition">'
                . htmlspecialchars($condition->title)
                . '</span>'
                . '<span class="alp-cm-kv-list__separator">:</span>'
                . '<span class="alp-cm-kv-list__value">'
                . htmlspecialchars($condition->value)
                . '</span>'
                . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
}
