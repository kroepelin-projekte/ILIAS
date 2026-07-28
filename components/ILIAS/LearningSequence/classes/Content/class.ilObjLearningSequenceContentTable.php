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

readonly class ilObjLearningSequenceContentTable
{
    public function __construct(
        private \ILIAS\UI\Factory $ui_factory,
        private \ILIAS\UI\Renderer $ui_renderer,
        private array $objects,
        private ?\ILIAS\UI\Component\Input\Container\Filter\Standard $filter = null
    ) {
    }

    public function render(): string
    {
        $data = $this->objects;
        $view_controls = [];

        if ($this->filter !== null) {
            $view_controls[] = $this->filter;
        }

        $environment = $this->buildEnvironment();

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

        return $this->ui_renderer->render($table);
    }

    private function buildEnvironment(): array
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

        $actions = function (\ilObjLearningSequenceContentData $record) {
            $dropdown_items = [];
            foreach ($record->actions as $action) {
                if ($action->is_divider) {
                    $dropdown_items[] = $this->ui_factory->divider()->horizontal();
                    continue;
                }
                $dropdown_items[] = $this->ui_factory->button()->shy($action->label, $action->link);
            }

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
