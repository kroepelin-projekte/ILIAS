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

namespace ILIAS\LearningSequence\LearningMap;

/**
 * Renders the learning map: a waterfall (top down) graph with branches and
 * merging paths in the adaptive mode, a chain running from left to right in
 * the sequential one - a monitor is wider than high, and the sequential map is
 * a single chain without branches.
 *
 * This class only produces the markup (tpl.lso_learning_map.html), the graph
 * data and the labels. Layout and drawing happen in the browser, see
 * resources/js/lso_learning_map.js; the styles live in
 * resources/css/lso_learning_map.css. Both assets are registered as public
 * assets in LearningSequence.php.
 */
class LSOLearningMapRenderer
{
    public const TEMPLATE = 'tpl.lso_learning_map.html';
    public const COMPONENT = 'components/ILIAS/LearningSequence';
    public const JS = 'assets/js/lso_learning_map.js';
    public const CSS = 'assets/css/lso_learning_map.css';

    public const TITLE = 'Learning Map'; // #ToDo Sprachvariable

    public const ORIENTATION_VERTICAL = 'vertical';
    public const ORIENTATION_HORIZONTAL = 'horizontal';

    // Box metrics, handed over to the javascript as "metrics".
    private const NODE_WIDTH = 190;
    private const NODE_HEIGHT = 86;
    private const H_GAP = 26;
    private const V_GAP = 96;

    /**
     * @param bool $sequential the learning sequence runs in the sequential
     *        mode. Its map is drawn horizontally and shows neither the walked
     *        path nor a current position - the mode simply does not keep them.
     */
    public function __construct(
        private \ILIAS\UI\Factory $ui_factory,
        private \ILIAS\UI\Renderer $ui_renderer,
        private \ilGlobalTemplateInterface $tpl,
        private bool $sequential = false
    ) {
    }

    /**
     * @param array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} $graph
     */
    public function render(array $graph): string
    {
        if (($graph['nodes'] ?? []) === []) {
            return $this->ui_renderer->render(
                $this->ui_factory->panel()->standard(
                    self::TITLE,
                    $this->ui_factory->messageBox()->info($this->getEmptyMessage())
                )
            );
        }

        return $this->ui_renderer->render(
            $this->ui_factory->panel()->standard(
                self::TITLE,
                $this->ui_factory->legacy()->content($this->buildMarkup($graph))
            )
        );
    }

    /**
     * The very same map, but without the panel around it - used where the
     * surrounding component already provides a frame and a title, e.g. the
     * modal of the kiosk player.
     *
     * @param array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} $graph
     */
    public function renderWithoutPanel(array $graph): string
    {
        if (($graph['nodes'] ?? []) === []) {
            return $this->ui_renderer->render(
                $this->ui_factory->messageBox()->info($this->getEmptyMessage())
            );
        }

        return $this->buildMarkup($graph);
    }

    protected function getEmptyMessage(): string
    {
        // #ToDo Sprachvariable
        return 'Für diese Lernsequenz können noch keine Wege dargestellt werden.'
            . ' Es fehlen Objekte oder ein Start-Objekt.';
    }

    /**
     * @param array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} $graph
     */
    protected function buildMarkup(array $graph): string
    {
        $this->tpl->addCss(self::CSS);
        $this->tpl->addJavaScript(self::JS);

        $map_id = 'lso_learning_map_' . substr(md5((string) mt_rand()), 0, 8);

        $template = new \ilTemplate(self::TEMPLATE, true, true, self::COMPONENT);
        $template->setVariable('MAP_ID', $map_id);
        // the mode is already marked here on the server: the script adds the
        // very same class, but only after the page has been built - too late
        // for the size of the modal in the kiosk player.
        $template->setVariable(
            'MAP_MODIFIER',
            $this->sequential ? ' lso-learning-map--horizontal' : ''
        );
        $template->setVariable('TOOLBAR', $this->renderToolbar($map_id));
        $template->setVariable('VIEWPORT_LABEL', $this->getViewportLabel());
        $template->setVariable('MAP_DATA', $this->getMapDataAsJson($graph));

        foreach ($this->getLegend() as $modifier => $label) {
            $template->setCurrentBlock('legend_item');
            $template->setVariable('LEGEND_MODIFIER', $modifier);
            $template->setVariable('LEGEND_LABEL', $label);
            $template->parseCurrentBlock();
        }

        return $template->get();
    }

    /**
     * Everything the javascript needs: the graph itself, the box metrics and
     * the labels it draws into the boxes.
     *
     * @param array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} $graph
     */
    protected function getMapDataAsJson(array $graph): string
    {
        return json_encode(
            $graph + [
                'orientation' => $this->sequential
                    ? self::ORIENTATION_HORIZONTAL
                    : self::ORIENTATION_VERTICAL,
                'metrics' => [
                    'node_width' => self::NODE_WIDTH,
                    'node_height' => self::NODE_HEIGHT,
                    'h_gap' => self::H_GAP,
                    'v_gap' => self::V_GAP,
                ],
                'labels' => $this->getNodeLabels() + $this->getScreenReaderLabels()
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
    }

    /**
     * @return array<string, string> the badge/button labels of a box
     */
    protected function getNodeLabels(): array
    {
        // #ToDo Sprachvariablen
        return [
            'current' => 'hier',
            'done' => 'erledigt',
            'blocked' => 'gesperrt',
            'open_object' => 'Öffnen'
        ];
    }

    /**
     * The map is a graph, and a graph is nothing a screen reader can look at.
     * The boxes are therefore drawn into an ordered list in learning order and
     * every entry carries its position, its state and where it leads to; the
     * texts for that are collected here (WCAG 1.1.1, 1.3.1).
     *
     * @return array<string, string>
     */
    protected function getScreenReaderLabels(): array
    {
        // #ToDo Sprachvariablen
        return [
            'sr_summary' => 'Lernkarte mit %s Objekten.',
            'sr_summary_current' => 'Ihre aktuelle Position: %s.',
            'sr_summary_start' => 'Beginn: %s.',
            'sr_summary_end' => 'Ende: %s.',
            'sr_step' => 'Objekt %1$s von %2$s',
            'sr_state_done' => 'Status: abgeschlossen',
            'sr_state_open' => 'Status: fortfahren möglich',
            'sr_state_blocked' => 'Status: gesperrt',
            'sr_current' => 'Ihre aktuelle Position',
            'sr_start' => 'Beginn der Lernsequenz',
            'sr_end' => 'Ende der Lernsequenz',
            'sr_leads_to' => 'Führt zu: %s.',
            'sr_leads_to_none' => 'Führt zu keinem weiteren Objekt.',
            'sr_blocked_way' => '%s (dieser Weg ist zurzeit gesperrt)',
            'sr_fitted' => 'Ansicht eingepasst.',
            'sr_zoom' => 'Zoom %s Prozent.',
            'sr_at_current' => 'Ansicht bei Ihrer aktuellen Position: %s.',
            'sr_no_current' => 'Es ist keine aktuelle Position bekannt.'
        ];
    }

    protected function getViewportLabel(): string
    {
        // #ToDo Sprachvariable
        return 'Lernkarte: Darstellung der Objekte und ihrer Reihenfolge';
    }

    /**
     * @return array<string, string> css-modifier of the swatch => label
     */
    protected function getLegend(): array
    {
        // #ToDo Sprachvariablen
        $legend = [
            'open' => 'fortfahren möglich',
            'blocked' => 'gesperrt',
            'path' => 'bisheriger Pfad',
            'done' => 'abgeschlossen',
            'current' => 'aktuelle Position'
        ];

        if ($this->sequential) {
            // the sequential mode keeps neither a walked path nor a current
            // position, so both swatches would explain something that is never
            // drawn
            unset($legend['path'], $legend['current']);
        }

        return $legend;
    }

    /**
     * Zoom and pan controls. Every button talks to the javascript api of its
     * map, which is registered as il.LSO.LearningMap.get(<map id>).
     */
    protected function renderToolbar(string $map_id): string
    {
        // #ToDo Sprachvariablen
        // "–", "+" and "100 %" say nothing when read out aloud, so every
        // button carries a spoken name of its own (WCAG 4.1.2, 2.4.6)
        $actions = [
            '–' => ['Verkleinern', 'zoomBy(-0.15)'],
            '+' => ['Vergrößern', 'zoomBy(0.15)'],
            '100 %' => ['Originalgröße, 100 Prozent', 'resetZoom()'],
            'Einpassen' => ['Ansicht einpassen', 'fit()'],
            'Zu meiner Position' => ['Zu meiner Position springen', 'focusCurrent()']
        ];

        if ($this->sequential) {
            // there is no current position in the sequential mode, and the
            // chain is drawn at its natural size without zooming, so the zoom
            // buttons would do nothing
            unset($actions['Zu meiner Position'], $actions['–'], $actions['+'], $actions['100 %']);
        }

        $buttons = [];
        foreach ($actions as $label => [$aria_label, $call]) {
            $buttons[] = $this->ui_factory->button()->standard((string) $label, '#')
                ->withAriaLabel($aria_label)
                ->withAdditionalOnLoadCode(
                    fn($id) => $this->bindToApi((string) $id, $map_id, $call)
                );
        }

        return $this->ui_renderer->render($buttons);
    }

    protected function bindToApi(string $button_id, string $map_id, string $call): string
    {
        return 'document.getElementById("' . $button_id . '").addEventListener("click", function (event) {'
            . 'event.preventDefault();'
            . 'var map = window.il && window.il.LSO && window.il.LSO.LearningMap'
            . ' ? window.il.LSO.LearningMap.get("' . $map_id . '") : null;'
            . 'if (map) { map.' . $call . '; }'
            . '});';
    }

    /**
     * Translates the map data (LSOLearningMap::toArray()) into the graph
     * structure the javascript draws. Nodes are keyed by obj_id, edges are
     * derived from the successors of every node.
     *
     * @param array<string, mixed> $map LSOLearningMap::toArray()
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function fromMapData(array $map): array
    {
        $map_nodes = $map['nodes'] ?? [];
        $start_obj_id = (int) ($map['start_obj_id'] ?? 0);
        $end_obj_id = (int) ($map['end_obj_id'] ?? 0);

        $known = [];
        foreach ($map_nodes as $node) {
            $known[(int) $node['obj_id']] = $node;
        }

        $nodes = [];
        $edges = [];
        foreach ($map_nodes as $node) {
            $obj_id = (int) $node['obj_id'];
            $situation = (string) ($node['situation'] ?? '');
            $can_access = (bool) ($node['can_access'] ?? false);

            // an object one may not enter is never "done", no matter what its
            // (possibly empty) set of output-conditions says
            $state = 'blocked';
            if ($can_access && !empty($node['has_completed'])) {
                $state = 'done';
            } elseif ($can_access) {
                $state = 'open';
            }

            $terminal = null;
            if ($obj_id === $start_obj_id || $situation === 'start') {
                $terminal = 'start';
            } elseif ($obj_id === $end_obj_id || $situation === 'end') {
                $terminal = 'end';
            }

            $nodes[] = [
                'id' => (string) $obj_id,
                'title' => (string) ($node['title'] ?? ''),
                'description' => (string) ($node['description'] ?? ''),
                'icon' => (string) ($node['icon'] ?? ''),
                'href' => $node['player_link'] ?? null,
                'state' => $state,
                'current' => (bool) ($node['is_current'] ?? false),
                'terminal' => $terminal,
            ];

            // an edge is passable only if this very edge may be used now: the
            // object may be left (its output-conditions, e.g. learning progress
            // "completed", are fulfilled) AND the target may be entered coming
            // from here. The data layer decides that per edge.
            $passable_successors = array_map('intval', (array) ($node['passable_successors'] ?? []));

            foreach ($node['successors'] ?? [] as $successor_obj_id) {
                $successor_obj_id = (int) $successor_obj_id;
                if (!isset($known[$successor_obj_id])) {
                    continue;
                }
                $successor = $known[$successor_obj_id];
                $edges[] = [
                    'from' => (string) $obj_id,
                    'to' => (string) $successor_obj_id,
                    'passable' => in_array($successor_obj_id, $passable_successors, true),
                    'on_path' => !empty($node['is_on_walked_path'])
                        && !empty($successor['is_on_walked_path']),
                ];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
