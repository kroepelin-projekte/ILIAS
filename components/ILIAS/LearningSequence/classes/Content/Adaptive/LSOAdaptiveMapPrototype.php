<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Adaptive;

use ILIAS\LearningSequence\Content\Condition\ConditionFactory;
use ILIAS\LearningSequence\Player\Map\LSMap;
use ILIAS\LearningSequence\Player\Map\LSMapNode;

final class LSOAdaptiveMapPrototype
{
    /**
     * @var array<int, string>
     */
    private array $condition_label_cache = [];

    public function __construct(
        private readonly LSMap $map,
        private readonly ConditionFactory $condition_factory
    ) {
    }

    public function render(): string
    {
        if ($this->map->nodes === []) {
            return '';
        }

        $rows = $this->getSortedRows();
        $slot_count = $this->getSlotCount($rows);

        $html = '<section class="alp-ls-map-prototype">';
        $html .= $this->renderInlineStyles();
        $html .= '<div class="alp-ls-map-prototype__header">';
        $html .= '<h3 class="alp-ls-map-prototype__title">Pfadkarte (Prototyp)</h3>';
        $html .= '<p class="alp-ls-map-prototype__intro">Tabellarische Visualisierung aller Objekte und moeglichen Pfade.</p>';
        $html .= $this->renderLegend();
        $html .= '</div>';
        $html .= '<div class="alp-ls-path-table-wrap">';
        $html .= '<table class="alp-ls-path-table" aria-label="Pfadkarte der Lernsequenz"><tbody>';

        $row_keys = array_keys($rows);
        foreach ($row_keys as $index => $row_key) {
            $nodes = $rows[$row_key];
            $html .= $this->renderNodeRow($nodes, $slot_count);

            if (isset($row_keys[$index + 1])) {
                $next_nodes = $rows[$row_keys[$index + 1]];
                $html .= $this->renderConnectorRows($nodes, $next_nodes, $slot_count);
            }
        }

        $html .= '</tbody></table>';
        $html .= '</div>';
        $html .= '</section>';

        return $html;
    }

    /**
     * @return array<int, LSMapNode[]>
     */
    private function getSortedRows(): array
    {
        $rows = [];
        $max_depth = $this->getMaxDepth();

        foreach ($this->map->nodes as $node) {
            $display_depth = $this->getDisplayDepth($node, $max_depth);
            $rows[$display_depth][] = $node;
        }

        ksort($rows);

        $predecessors = $this->buildPredecessorIndex();
        $weights = [];

        foreach ($rows as $depth => $nodes) {
            usort(
                $nodes,
                function (LSMapNode $left, LSMapNode $right) use ($predecessors, $weights): int {
                    $left_weight = $this->getNodeWeight($left, $predecessors, $weights);
                    $right_weight = $this->getNodeWeight($right, $predecessors, $weights);

                    if ($left_weight !== $right_weight) {
                        return $left_weight <=> $right_weight;
                    }

                    if ($left->is_current !== $right->is_current) {
                        return $left->is_current ? -1 : 1;
                    }

                    $title_compare = strnatcasecmp($left->title, $right->title);
                    if ($title_compare !== 0) {
                        return $title_compare;
                    }

                    return $left->obj_id <=> $right->obj_id;
                }
            );

            foreach ($nodes as $index => $node) {
                $weights[$node->obj_id] = $index;
            }

            $rows[$depth] = $nodes;
        }

        return $rows;
    }

    /**
     * @param array<int, LSMapNode[]> $rows
     */
    private function getSlotCount(array $rows): int
    {
        $max_columns = max(array_map('count', $rows));
        return max(3, ($max_columns * 2) + 1);
    }

    private function getMaxDepth(): int
    {
        $depths = array_map(
            static fn(LSMapNode $node): int => $node->depth,
            $this->map->nodes
        );

        return $depths === [] ? 0 : max($depths);
    }

    private function getDisplayDepth(LSMapNode $node, int $max_depth): int
    {
        if ($node->obj_id === $this->map->start_obj_id) {
            return 0;
        }

        if ($node->obj_id === $this->map->end_obj_id && $this->map->end_obj_id !== 0) {
            return $max_depth + 1;
        }

        return max(1, $node->depth);
    }

    /**
     * @return array<int, int[]>
     */
    private function buildPredecessorIndex(): array
    {
        $predecessors = [];

        foreach ($this->map->nodes as $node) {
            $predecessors[$node->obj_id] ??= [];
            foreach ($node->successors as $successor_obj_id) {
                $predecessors[$successor_obj_id] ??= [];
                $predecessors[$successor_obj_id][] = $node->obj_id;
            }
        }

        return $predecessors;
    }

    /**
     * @param array<int, int[]> $predecessors
     * @param array<int, int> $weights
     */
    private function getNodeWeight(LSMapNode $node, array $predecessors, array $weights): float
    {
        $node_predecessors = $predecessors[$node->obj_id] ?? [];
        $known_weights = [];

        foreach ($node_predecessors as $predecessor_obj_id) {
            if (array_key_exists($predecessor_obj_id, $weights)) {
                $known_weights[] = $weights[$predecessor_obj_id];
            }
        }

        if ($known_weights === []) {
            return 0.0;
        }

        return array_sum($known_weights) / count($known_weights);
    }

    /**
     * @param LSMapNode[] $nodes
     */
    private function renderNodeRow(array $nodes, int $slot_count): string
    {
        $slot_map = $this->buildNodeSlots($nodes, $slot_count);
        $cells = '';

        for ($slot = 1; $slot <= $slot_count; $slot++) {
            if (isset($slot_map[$slot])) {
                $cells .= '<td class="alp-ls-path-table__cell alp-ls-path-table__node-cell">'
                    . $this->renderNode($slot_map[$slot])
                    . '</td>';
                continue;
            }

            $cells .= '<td class="alp-ls-path-table__cell alp-ls-path-table__empty-node-cell"></td>';
        }

        return '<tr class="alp-ls-path-table__node-row">' . $cells . '</tr>';
    }

    /**
     * @param LSMapNode[] $from_nodes
     * @param LSMapNode[] $to_nodes
     */
    private function renderConnectorRows(array $from_nodes, array $to_nodes, int $slot_count): string
    {
        $from_slots = $this->buildNodeSlots($from_nodes, $slot_count);
        $to_slots = $this->buildNodeSlots($to_nodes, $slot_count);
        $top_stub = [];
        $middle = [];
        $bottom_stub = [];

        $slot_by_from_obj_id = [];
        foreach ($from_slots as $slot => $node) {
            $slot_by_from_obj_id[$node->obj_id] = $slot;
        }

        $slot_by_to_obj_id = [];
        foreach ($to_slots as $slot => $node) {
            $slot_by_to_obj_id[$node->obj_id] = $slot;
        }

        foreach ($from_nodes as $node) {
            $from_slot = $slot_by_from_obj_id[$node->obj_id] ?? null;
            if ($from_slot === null) {
                continue;
            }

            $successors_in_next_row = array_values(array_filter(
                $node->successors,
                static fn(int $successor_obj_id): bool => isset($slot_by_to_obj_id[$successor_obj_id])
            ));

            if ($successors_in_next_row === []) {
                continue;
            }

            $top_stub[$from_slot]['vertical'] = true;

            foreach ($successors_in_next_row as $successor_obj_id) {
                $to_slot = $slot_by_to_obj_id[$successor_obj_id];
                $bottom_stub[$to_slot]['vertical'] = true;
                $middle[$from_slot]['vertical'] = true;
                $middle[$to_slot]['vertical'] = true;

                if ($from_slot === $to_slot) {
                    continue;
                }

                for ($slot = min($from_slot, $to_slot); $slot <= max($from_slot, $to_slot); $slot++) {
                    $middle[$slot]['horizontal'] = true;
                }
            }
        }

        return $this->renderConnectorRow($top_stub, $slot_count)
            . $this->renderConnectorRow($middle, $slot_count)
            . $this->renderConnectorRow($bottom_stub, $slot_count);
    }

    /**
     * @param array<int, array<string, bool>> $features_by_slot
     */
    private function renderConnectorRow(array $features_by_slot, int $slot_count): string
    {
        $cells = '';

        for ($slot = 1; $slot <= $slot_count; $slot++) {
            $classes = ['alp-ls-path-table__cell', 'alp-ls-path-table__connector-cell'];
            $features = $features_by_slot[$slot] ?? [];

            if (($features['horizontal'] ?? false) === true) {
                $classes[] = 'alp-ls-path-table__connector-cell--horizontal';
            }
            if (($features['vertical'] ?? false) === true) {
                $classes[] = 'alp-ls-path-table__connector-cell--vertical';
            }

            $cells .= '<td class="' . implode(' ', $classes) . '"></td>';
        }

        return '<tr class="alp-ls-path-table__connector-row">' . $cells . '</tr>';
    }

    /**
     * @param LSMapNode[] $nodes
     * @return array<int, LSMapNode>
     */
    private function buildNodeSlots(array $nodes, int $slot_count): array
    {
        $node_count = count($nodes);
        if ($node_count === 0) {
            return [];
        }

        $first_slot = (int) floor(($slot_count - (($node_count - 1) * 2)) / 2) + 1;
        $slots = [];

        foreach (array_values($nodes) as $index => $node) {
            $slots[$first_slot + ($index * 2)] = $node;
        }

        return $slots;
    }

    private function renderLegend(): string
    {
        return '<div class="alp-ls-map-prototype__legend">'
            . '<span class="alp-ls-map-prototype__legend-item">Blau = aktuelle Position</span>'
            . '<span class="alp-ls-map-prototype__legend-item">Gruen = abgeschlossen</span>'
            . '<span class="alp-ls-map-prototype__legend-item">Orange = besucht</span>'
            . '<span class="alp-ls-map-prototype__legend-item">Rot gestrichelt = blockiert</span>'
            . '</div>';
    }

    private function renderNode(LSMapNode $node): string
    {
        $classes = ['alp-ls-path-table__node'];

        if ($node->obj_id === $this->map->start_obj_id) {
            $classes[] = 'alp-ls-path-table__node--start';
        }
        if ($node->obj_id === $this->map->end_obj_id) {
            $classes[] = 'alp-ls-path-table__node--end';
        }
        if ($node->is_current) {
            $classes[] = 'alp-ls-path-table__node--current';
        }
        if ($node->has_completed) {
            $classes[] = 'alp-ls-path-table__node--completed';
        } elseif ($node->has_visited) {
            $classes[] = 'alp-ls-path-table__node--visited';
        }
        if (!$node->can_access) {
            $classes[] = 'alp-ls-path-table__node--blocked';
        }

        $html = '<div class="' . implode(' ', $classes) . '" title="' . htmlspecialchars($this->buildTooltip($node), ENT_QUOTES) . '">';
        $html .= '<div class="alp-ls-path-table__node-kicker">' . htmlspecialchars($this->getNodeKicker($node)) . '</div>';
        $html .= '<div class="alp-ls-path-table__node-title">' . htmlspecialchars($node->title) . '</div>';
        $html .= '<div class="alp-ls-path-table__node-state">' . htmlspecialchars($this->getNodeState($node)) . '</div>';
        $html .= '</div>';

        return $html;
    }

    private function getNodeKicker(LSMapNode $node): string
    {
        $labels = [];

        if ($node->obj_id === $this->map->start_obj_id) {
            $labels[] = 'Start';
        }
        if ($node->obj_id === $this->map->end_obj_id) {
            $labels[] = 'Ende';
        }
        if ($node->is_current) {
            $labels[] = 'Aktuell';
        }

        return $labels === [] ? 'Objekt' : implode(' · ', $labels);
    }

    private function getNodeState(LSMapNode $node): string
    {
        $states = [];

        $states[] = $node->can_access ? 'betretbar' : 'blockiert';

        if ($node->has_completed) {
            $states[] = 'abgeschlossen';
        } elseif ($node->has_visited) {
            $states[] = 'besucht';
        } else {
            $states[] = 'offen';
        }

        return implode(' · ', $states);
    }

    private function buildTooltip(LSMapNode $node): string
    {
        $parts = [
            $node->title,
            'Zugriff: ' . ($node->can_access ? 'moeglich' : 'gesperrt'),
            'Besuche: ' . $node->visit_count,
            'Letzter Besuch: ' . $this->formatLastVisited($node->last_visited_ts),
            'Input: ' . implode(', ', $this->getConditionLabels($node->input_condition_ids)),
            'Output: ' . implode(', ', $this->getConditionLabels($node->output_condition_ids)),
        ];

        return implode(' | ', $parts);
    }

    /**
     * @param int[] $condition_ids
     * @return string[]
     */
    private function getConditionLabels(array $condition_ids): array
    {
        if ($condition_ids === []) {
            return ['(keine)'];
        }

        $labels = [];
        foreach ($condition_ids as $condition_id) {
            $labels[] = $this->getConditionLabel($condition_id);
        }

        return $labels;
    }

    private function getConditionLabel(int $condition_id): string
    {
        if (isset($this->condition_label_cache[$condition_id])) {
            return $this->condition_label_cache[$condition_id];
        }

        try {
            $condition = $this->condition_factory->getConditionInstanceById($condition_id);
            $label = $condition->getName();
        } catch (\Throwable) {
            $label = null;
        }

        $this->condition_label_cache[$condition_id] = $label !== null && $label !== ''
            ? $label
            : 'Bedingung #' . $condition_id;

        return $this->condition_label_cache[$condition_id];
    }

    private function formatLastVisited(?int $timestamp): string
    {
        if ($timestamp === null) {
            return 'nie';
        }

        return date('d.m.Y H:i', $timestamp);
    }

    private function renderInlineStyles(): string
    {
        $css_file = dirname(__DIR__, 3) . '/resources/css/alp_learning_sequence_map.css';
        if (!is_readable($css_file)) {
            return '';
        }

        $css = file_get_contents($css_file);

        if (!is_string($css) || trim($css) === '') {
            return '';
        }

        return '<style>' . $css . '</style>';
    }
}
