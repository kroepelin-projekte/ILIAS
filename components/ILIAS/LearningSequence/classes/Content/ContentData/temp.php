<?php

/**
 * LearningSequence / ALP: Kitchen-Sink / Dummy Presentation Table.
 *
 * Warum gibt es diese Klasse?
 * - Für ein Ticket/Prototyp möchten wir im Content-Tab der Learning Sequence
 *   eine zusätzliche Presentation-Table anzeigen.
 * - Die Table soll bewusst mit Dummy-Daten arbeiten (keine DB, keine Sprachvariablen).
 *
 * Wichtige Vorgabe aus dem Ticket:
 * - Daten und Darstellung sauber trennen.
 *   -> Die GUI baut Dummy-Daten als DTOs und übergibt sie an diese Table.
 *   -> Diese Klasse rendert ausschließlich (keine Datenbeschaffung).
 *
 * Hinweis:
 * - Diese Table ist NICHT produktiv gedacht, sondern als Kitchen-Sink-Demo,
 *   deshalb sind Labels/Strings komplett hard coded.
 */

declare(strict_types=1);

readonly class ilLearningSequenceALPContentManagementPresentationTable
{
    public function __construct(
        private ILIAS\UI\Factory $ui_factory,
        private ILIAS\UI\Renderer $ui_renderer,
        /**
         * @var ilLearningSequenceALPContentManagementObjectDTO[]
         */
        private array $objects
    ) {
    }

    /**
     * Einstiegspunkt: baut die Presentation-Table auf und liefert fertiges HTML.
     */
    public function render(): string
    {
        // 1) Daten kommen als DTOs von außen.
        //    Wichtig: Die GUI-Klasse ist die Datenquelle und baut die DTOs.
        $data = $this->objects;

        // 2) Presentation Tables erwarten View-Controls als Array.
        //    Für dieses Dummy-Beispiel brauchen wir keine Filter/Controls.
        $view_controls = [];

        // 3) Environment: Helper-Funktionen, die wir im Mapping verwenden.
        $environment = $this->buildEnvironment();

        // 4) Mapping: verwandelt einen DTO-Datensatz in eine PresentationRow.
        $mapping = function ($row, ilLearningSequenceALPContentManagementObjectDTO $record, $ui_factory, array $env) {
            // Leading Icon links vom Titel.
            // Wir nutzen ILIAS-Standardicons (kein Plugin-Asset), daher ilUtil::getImagePath().
            $leading_icon = $ui_factory->symbol()->icon()->custom(
                $record->icon_path,
                ''
            );

            // Rechte Aktionen: technisch kann die Row nur EIN Action-Element bekommen.
            // Wir setzen daher das geforderte Action-Menü als Dropdown.
            $actions = $env['actions']($record);

            // Ausklapp-Content: links Conditions, rechts Info-Box.
            $content = $env['content']($record);

            // Ticket-Anforderung (neuer Wunsch):
            // Der Titel (z.B. "Object B") soll später direkt auf das Objekt verlinken.
            // Da PresentationRow::withHeadline() nur einen STRING akzeptiert, rendern wir
            // den UI-Link als HTML-String und geben diesen String als Headline weiter.
            // (Im Presentation-Row-Template wird {HEADLINE} direkt ausgegeben.)
            $headline = $env['headline']($record);

            return $row
                ->withHeadline($headline)
                ->withLeadingSymbol($leading_icon)
                ->withSubheadline($record->description)
                ->withContent($content)
                ->withAction($actions);
        };

        $table = $this->ui_factory->table()->presentation(
            'Content Managent',
            $view_controls,
            $mapping
        )
                                  ->withEnvironment($environment)
                                  ->withData($data);

        return $this->ui_renderer->render($table);
    }

    /**
     * Environment = Sammlung kleiner Helfer, die im Mapping genutzt werden.
     *
     * Vorteil:
     * - Das Mapping bleibt kurz und verständlich.
     * - Komplexes HTML/Layout steckt in dedizierten Methoden.
     */
    private function buildEnvironment(): array
    {
        // Headline als Link.
        // Wichtig: Der PresentationRow-Headline ist technisch nur ein String.
        // Wir erzeugen daher den Link als UI-Komponente und rendern ihn zu HTML,
        // das wir dann als String zurückgeben.
        $headline = function (ilLearningSequenceALPContentManagementObjectDTO $record): string {
            $title = $record->title;
            $href = $record->link;

            // Ticket-Anforderung (aktualisiert):
            // Wir nutzen wieder HTML/CSS für Badges und den ausklappbaren Content.
            // ABER: Kein Inline-CSS mehr.
            // -> Styling erfolgt ausschließlich über eine ausgelagerte CSS-Datei.
            $link_html = $this->ui_renderer->render(
                $this->ui_factory->link()->standard($title, $href)
            );

            // Badges (Online/Offline + Start/End) direkt neben dem Titel.
            $badges_html = '';

            // Online/Offline als Badge (grün/rot).
            if ($record->is_online) {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--online">Online</span>';
            } else {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--offline">Offline</span>';
            }

            // Option A: Start/Ende NUR beim betreffenden Objekt anzeigen.
            if ($record->is_start_object) {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--start">Start</span>';
            }
            if ($record->is_end_object) {
                $badges_html .= ' <span class="alp-cm-badge alp-cm-badge--end">End</span>';
            }

            return $link_html . $badges_html;
        };

        // Dropdown ("Action Button") mit den Ticket-Einträgen.
        $actions = function (ilLearningSequenceALPContentManagementObjectDTO $record) {
            // Ticket-Anforderung:
            // - Zusätzlich zu den Condition-Actions soll es:
            //   - Set Online (alternativ Set Offline, wenn online)
            //   - Set Start Objekt
            //   - Set End Objekt
            // geben. Diese Einträge kommen DIREKT nach "Edit/Add Condition".
            $online_toggle_label = $record->is_online ? 'Set Offline' : 'Set Online';

            $dropdown_items = [
                $this->ui_factory->button()->shy('Edit Condition', '#'),
                $this->ui_factory->button()->shy('Add Condition', '#'),
                $this->ui_factory->divider()->horizontal(),
                $this->ui_factory->button()->shy($online_toggle_label, '#'),
                $this->ui_factory->button()->shy('Set Start Objekt', '#'),
                $this->ui_factory->button()->shy('Set End Objekt', '#'),
                $this->ui_factory->divider()->horizontal(),
                $this->ui_factory->button()->shy('Object Setting', '#'),
                $this->ui_factory->button()->shy('Delete Object', '#'),
            ];

            return $this->ui_factory->dropdown()->standard($dropdown_items)->withLabel('Action');
        };

        // Content (ausklappbar): links Conditions, rechts graue Info-Box.
        $content = function (ilLearningSequenceALPContentManagementObjectDTO $record) {
            // Ticket-Anforderung (Rückbau):
            // Wir nutzen wieder HTML (wie zuvor), um das gewünschte Layout exakt zu treffen.
            // Wichtig: Kein Inline-CSS mehr, nur CSS-Klassen.
            $conditions_html = $this->renderConditionsHtml($record);
            $info_html = $this->renderInformationHtml($record->information);

            // Wichtig für die Darstellung:
            // Wir wollen, dass die rechte Box ("Information") in jeder Row die gleiche Breite hat.
            // Bei "dynamicallyDistributed" bestimmt der Inhalt die Breite (flex-grow), was je nach Textlänge
            // zu unterschiedlich breiten Boxen führen kann.
            // "evenlyDistributed" erzwingt gleich breite Spalten und stabilisiert dadurch die Boxbreite.
            return $this->ui_factory->layout()->alignment()->horizontal()->evenlyDistributed(
                $this->ui_factory->legacy()->content($conditions_html),
                $this->ui_factory->legacy()->content($info_html)
            );
        };

        return [
            'headline' => $headline,
            'actions' => $actions,
            'content' => $content,
        ];
    }

    /**
     * Rendert den Conditions-Block (Input/Output) als HTML.
     *
     * Wichtig:
     * - Wir verwenden hier absichtlich HTML, weil wir die Struktur exakt benötigen.
     * - Styling erfolgt NICHT inline, sondern über CSS-Klassen.
     */
    private function renderConditionsHtml(ilLearningSequenceALPContentManagementObjectDTO $record): string
    {
        $input = $record->input_conditions;
        $output = $record->output_conditions;

        $html = '';
        $html .= '<div class="alp-cm-conditions">';

        // Ticket-Anforderung:
        // - Schreibweise: "Input conditions" / "Output conditions"
        // - "You have selected" soll NICHT mehr angezeigt werden.
        $html .= '<h4 class="alp-cm-conditions__title">Input conditions</h4>';
        $html .= $this->renderKeyValueList($input);

        $html .= '<h4 class="alp-cm-conditions__title alp-cm-conditions__title--spaced">Output conditions</h4>';
        $html .= $this->renderKeyValueList($output);

        $html .= '</div>';
        return $html;
    }

    /**
     * Rendert Conditions als Key-Value-Liste.
     *
     * Ticket-Anforderung:
     * - Input/Output-Conditions sollen Paare sein (Condition + Value).
     * - Kein "=>".
     */
    private function renderKeyValueList(array $conditions): string
    {
        if ($conditions === []) {
            return '<div class="alp-cm-conditions__empty">(keine)</div>';
        }

        $html = '<ul class="alp-cm-kv-list">';
        foreach ($conditions as $condition) {
            if (!$condition instanceof ilLearningSequenceALPConditionDTO) {
                // Defensive: Sollte in der Praxis nicht passieren.
                $html .= '<li class="alp-cm-kv-list__item">'
                    . htmlspecialchars((string) $condition)
                    . '</li>';
                continue;
            }

            $html .= '<li class="alp-cm-kv-list__item">'
                . '<span class="alp-cm-kv-list__condition">'
                . htmlspecialchars($condition->condition)
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

    /**
     * Rendert die rechte, graue "Information"-Box als HTML.
     *
     * Ticket-Anforderung:
     * - Online/Offline wird als Badge am Titel angezeigt (nicht mehr hier).
     * - Start/End wird als Badge am Titel angezeigt (nicht mehr hier).
     */
    private function renderInformationHtml(ilLearningSequenceALPInformationDTO $info): string
    {
        $prev = $info->previous_object;
        $next = $info->next_object;

        $html = '';
        $html .= '<div class="alp-cm-info">';
        $html .= '<h4 class="alp-cm-info__title">Information</h4>';
        $html .= '<div class="alp-cm-info__item"><strong>Previous Object</strong> ' . htmlspecialchars((string) $prev) . '</div>';
        $html .= '<div class="alp-cm-info__item"><strong>Next Object</strong> ' . htmlspecialchars((string) $next) . '</div>';
        $html .= '</div>';

        return $html;
    }
}
