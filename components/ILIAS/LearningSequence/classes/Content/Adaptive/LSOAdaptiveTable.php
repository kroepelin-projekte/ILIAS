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

namespace ILIAS\LearningSequence\Content\Adaptive;

/**
 * Renders the adaptive learning sequence content table.
 */
readonly class LSOAdaptiveTable
{
    /**
     * Creates the adaptive content table.
     *
     * @param \ilObjLearningSequenceContentData[] $objects Content data records.
     */
    public function __construct(
        /** UI factory. */
        private \ILIAS\UI\Factory $ui_factory,
        /** UI renderer. */
        private \ILIAS\UI\Renderer $ui_renderer,
        /** @var \ilObjLearningSequenceContentData[] Content data records. */
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
            $this->lng->txt('lso_adaptive_content_management'),
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
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--online">'
                    . $this->lng->txt('lso_adaptive_online') . '</span>';
            } else {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--offline">'
                    . $this->lng->txt('lso_adaptive_offline') . '</span>';
            }

            if ($record->start_object !== '') {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--start">'
                    . $this->lng->txt('lso_adaptive_start') . '</span>';
            }
            if ($record->end_object !== '') {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--end">'
                    . $this->lng->txt('lso_adaptive_end') . '</span>';
            }
            if ($record->end_object === '' && !$record->has_structural_successor) {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--dead-end">'
                    . $this->lng->txt('lso_adaptive_dead_end') . '</span>';
            }
            if ($record->has_conflicting_input_configuration) {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--misconfigured">'
                    . $this->lng->txt('lso_adaptive_misconfigured') . '</span>'
                    . $this->renderMisconfigurationPopoverTrigger($record);
            }
            if ($record->start_object === '' && !$record->has_structural_predecessor) {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--entry-point">'
                    . $this->lng->txt('lso_adaptive_entry_point') . '</span>';
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
            $previous_objects = trim($record->previous_objects);
            $next_objects = trim($record->next_objects);

            $html_conditions = '<div class="alp-cm-conditions">';
            $html_conditions .= '<h4 class="alp-cm-conditions__title">'
                . $this->lng->txt('lso_adaptive_input_conditions') . '</h4>';
            $html_conditions .= $this->renderKeyValueList($input);
            $html_conditions .= '<h4 class="alp-cm-conditions__title alp-cm-conditions__title--spaced">'
                . $this->lng->txt('lso_adaptive_output_conditions') . '</h4>';
            $html_conditions .= $this->renderKeyValueList($output);
            $html_conditions .= '</div>';

            $html_info = '<div class="alp-cm-info">';
            $html_info .= '<h4 class="alp-cm-info__title">'
                . $this->lng->txt('lso_adaptive_information') . '</h4>';
            $html_info .= '<div class="alp-cm-info__item"><span class="alp-cm-info__label">'
                . $this->lng->txt('lso_adaptive_previous_object') . ':</span> '
                . $this->renderInfoValue($previous_objects) . '</div>';
            $html_info .= '<div class="alp-cm-info__item"><span class="alp-cm-info__label">'
                . $this->lng->txt('lso_adaptive_next_object') . ':</span> '
                . $this->renderInfoValue($next_objects) . '</div>';
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

    private function renderMisconfigurationPopoverTrigger(\ilObjLearningSequenceContentData $record): string
    {
        if ($record->static_input_configuration_issue_details === []) {
            return '';
        }

        $items = [];
        $issue_details = $record->static_input_configuration_issue_details;
        foreach ($issue_details as $index => $detail) {
            $item = $this->ui_factory->item()->standard(
                $this->lng->txt($detail->title_language_var)
            );

            $description = $this->buildIssueDetailDescription($detail);
            if ($description !== null && $description !== '') {
                $item = $item->withDescription($description);
            }

            $items[] = $item;

            if ($index < count($issue_details) - 1) {
                $items[] = $this->ui_factory->divider()->horizontal();
            }
        }

        $popover = $this->ui_factory->popover()
            ->listing($items)
            ->withTitle($this->lng->txt('lso_adaptive_misconfigured'))
            ->withVerticalPosition();
        $trigger = $this->ui_factory->button()
            ->shy('', '')
            ->withSymbol($this->ui_factory->symbol()->glyph()->help())
            ->withAriaLabel($this->lng->txt('lso_adaptive_misconfigured_details'))
            ->withOnClick($popover->getShowSignal());

        return ' ' . $this->ui_renderer->render([$popover, $trigger]);
    }

    /**
     * @return string|null
     */
    private function buildIssueDetailDescription(
        \ILIAS\LearningSequence\Content\Condition\StaticInputConfigurationIssueDetail $detail
    ): ?string {
        $parts = [];

        if ($detail->description_language_var !== null) {
            $parts[] = $this->lng->txt($detail->description_language_var);
        }

        foreach ($detail->properties_by_language_var as $language_var => $value) {
            $parts[] = sprintf(
                '%s: %s',
                $this->lng->txt($language_var),
                is_array($value) ? $this->renderIssueDetailPropertyList($value) : $value
            );
        }

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<int, int|string> $values
     */
    private function renderIssueDetailPropertyList(array $values): string
    {
        $items = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $items[] = \ilObject::_lookupTitle(\ilObject::_lookupObjId($value));
                continue;
            }

            $items[] = $value;
        }

        sort($items);

        return implode(', ', $items);
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
     * @param \ilObjLearningSequenceConditionData[] $conditions
     */
    private function renderKeyValueList(array $conditions): string
    {
        if ($conditions === []) {
            return '<div class="alp-cm-conditions__empty">'
                . $this->lng->txt('lso_adaptive_conditions_empty')
                . '</div>';
        }

        $html = '<ul class="alp-cm-kv-list">';
        foreach ($conditions as $condition) {
            $html .= '<li class="alp-cm-kv-list__item">'
                . '<span class="alp-cm-kv-list__condition">'
                . htmlspecialchars($condition->title)
                . '</span>';

            if ($condition->value !== '') {
                $html .= '<span class="alp-cm-kv-list__separator">:</span>'
                    . '<span class="alp-cm-kv-list__value">'
                    . htmlspecialchars($condition->value)
                    . '</span>';
            }

            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    private function renderInfoValue(string $value): string
    {
        $no_conditions = trim($this->lng->txt('no_conditions'));
        if ($value !== $no_conditions) {
            return '<span class="alp-cm-info__value">' . htmlspecialchars($value) . '</span>';
        }

        if (!preg_match('/^([^\p{L}\p{N}]*)((?:[\p{L}\p{N}]+(?:\s+[\p{L}\p{N}]+)*)?)([^\p{L}\p{N}]*)$/u', $no_conditions, $matches)) {
            return '<span class="alp-cm-info__value alp-cm-info__no-conditions">'
                . htmlspecialchars($value)
                . '</span>';
        }

        return '<span class="alp-cm-info__value">'
            . htmlspecialchars($matches[1])
            . '<span class="alp-cm-info__no-conditions">' . htmlspecialchars($matches[2]) . '</span>'
            . htmlspecialchars($matches[3])
            . '</span>';
    }
}
