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
        $max_columns = max(array_map('count', $rows));

        $html = '<section class="alp-ls-map-prototype">';
        $html .= '<div class="alp-ls-map-prototype__header">';
        $html .= '<h3 class="alp-ls-map-prototype__title">Pfadkarte (Prototyp)</h3>';
        $html .= '<p class="alp-ls-map-prototype__intro">Visualisierung aller LSO-Objekte und moeglichen Pfade fuer den aktuellen Benutzerstatus.</p>';
        $html .= $this->renderLegend();
        $html .= '</div>';
        $html .= '<div class="alp-ls-map" data-alp-ls-map style="--alp-ls-map-max-columns: ' . $max_columns . ';">';
        $html .= '<svg class="alp-ls-map__edges" aria-hidden="true"></svg>';
        $html .= '<div class="alp-ls-map__rows">';

        foreach ($rows as $depth => $nodes) {
            $html .= '<div class="alp-ls-map__row">';
            $html .= '<div class="alp-ls-map__row-label">' . htmlspecialchars($this->getRowLabel($depth, $nodes)) . '</div>';
            $html .= '<div class="alp-ls-map__row-grid" style="--alp-ls-map-row-columns: ' . count($nodes) . ';">';
            foreach ($nodes as $node) {
                $html .= $this->renderNode($node);
            }
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
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
    private function getRowLabel(int $depth, array $nodes): string
    {
        foreach ($nodes as $node) {
            if ($node->obj_id === $this->map->start_obj_id) {
                return 'Start';
            }
            if ($node->obj_id === $this->map->end_obj_id) {
                return 'Ende';
            }
        }

        return 'Ebene ' . $depth;
    }

    private function renderLegend(): string
    {
        return '<div class="alp-ls-map-prototype__legend">'
            . '<span class="alp-ls-map-prototype__legend-item"><span class="alp-ls-map-prototype__legend-swatch alp-ls-map-prototype__legend-swatch--current"></span>Aktuelle Position</span>'
            . '<span class="alp-ls-map-prototype__legend-item"><span class="alp-ls-map-prototype__legend-swatch alp-ls-map-prototype__legend-swatch--completed"></span>Abgeschlossen</span>'
            . '<span class="alp-ls-map-prototype__legend-item"><span class="alp-ls-map-prototype__legend-swatch alp-ls-map-prototype__legend-swatch--visited"></span>Besucht</span>'
            . '<span class="alp-ls-map-prototype__legend-item"><span class="alp-ls-map-prototype__legend-swatch alp-ls-map-prototype__legend-swatch--blocked"></span>Derzeit nicht betretbar</span>'
            . '</div>';
    }

    private function renderNode(LSMapNode $node): string
    {
        $classes = ['alp-ls-map-node'];
        if ($node->obj_id === $this->map->start_obj_id) {
            $classes[] = 'alp-ls-map-node--start';
        }
        if ($node->obj_id === $this->map->end_obj_id) {
            $classes[] = 'alp-ls-map-node--end';
        }
        if ($node->is_current) {
            $classes[] = 'alp-ls-map-node--current';
        }
        if ($node->has_completed) {
            $classes[] = 'alp-ls-map-node--completed';
        } elseif ($node->has_visited) {
            $classes[] = 'alp-ls-map-node--visited';
        }
        if (!$node->can_access) {
            $classes[] = 'alp-ls-map-node--blocked';
        }
        if ($node->is_on_walked_path) {
            $classes[] = 'alp-ls-map-node--walked';
        }

        $html = '<article class="' . implode(' ', $classes) . '"'
            . ' data-alp-ls-map-node'
            . ' data-obj-id="' . $node->obj_id . '"'
            . ' data-successors="' . htmlspecialchars(implode(',', $node->successors), ENT_QUOTES) . '"'
            . ' data-is-current="' . ($node->is_current ? '1' : '0') . '"'
            . ' data-on-walked-path="' . ($node->is_on_walked_path ? '1' : '0') . '"'
            . '>';
        $html .= '<header class="alp-ls-map-node__header">';
        $html .= '<div class="alp-ls-map-node__title-wrap">';
        $html .= '<h4 class="alp-ls-map-node__title">' . htmlspecialchars($node->title) . '</h4>';
        $html .= '<div class="alp-ls-map-node__badges">' . $this->renderBadges($node) . '</div>';
        $html .= '</div>';
        $html .= '</header>';

        if ($node->description !== '') {
            $html .= '<p class="alp-ls-map-node__description">' . htmlspecialchars($node->description) . '</p>';
        }

        $html .= '<dl class="alp-ls-map-node__meta">';
        $html .= '<div><dt>Zugriff</dt><dd>' . ($node->can_access ? 'moeglich' : 'gesperrt') . '</dd></div>';
        $html .= '<div><dt>Besuche</dt><dd>' . $node->visit_count . '</dd></div>';
        $html .= '<div><dt>Letzter Besuch</dt><dd>' . htmlspecialchars($this->formatLastVisited($node->last_visited_ts)) . '</dd></div>';
        $html .= '</dl>';

        $html .= '<div class="alp-ls-map-node__conditions">';
        $html .= $this->renderConditionGroup('Input', $node->input_condition_ids);
        $html .= $this->renderConditionGroup('Output', $node->output_condition_ids);
        $html .= '</div>';
        $html .= '</article>';

        return $html;
    }

    private function renderBadges(LSMapNode $node): string
    {
        $badges = [];

        if ($node->obj_id === $this->map->start_obj_id) {
            $badges[] = '<span class="alp-ls-map-node__badge alp-ls-map-node__badge--start">Start</span>';
        }
        if ($node->obj_id === $this->map->end_obj_id) {
            $badges[] = '<span class="alp-ls-map-node__badge alp-ls-map-node__badge--end">Ende</span>';
        }
        if ($node->is_current) {
            $badges[] = '<span class="alp-ls-map-node__badge alp-ls-map-node__badge--current">Aktuell</span>';
        }
        if ($node->has_completed) {
            $badges[] = '<span class="alp-ls-map-node__badge alp-ls-map-node__badge--completed">Abgeschlossen</span>';
        } elseif ($node->has_visited) {
            $badges[] = '<span class="alp-ls-map-node__badge alp-ls-map-node__badge--visited">Besucht</span>';
        }
        if (!$node->can_access) {
            $badges[] = '<span class="alp-ls-map-node__badge alp-ls-map-node__badge--blocked">Blockiert</span>';
        }

        return implode('', $badges);
    }

    /**
     * @param int[] $condition_ids
     */
    private function renderConditionGroup(string $label, array $condition_ids): string
    {
        $html = '<div class="alp-ls-map-node__condition-group">';
        $html .= '<h5 class="alp-ls-map-node__condition-title">' . htmlspecialchars($label) . '</h5>';

        if ($condition_ids === []) {
            $html .= '<div class="alp-ls-map-node__condition-empty">(keine)</div>';
            $html .= '</div>';
            return $html;
        }

        $html .= '<ul class="alp-ls-map-node__condition-list">';
        foreach ($this->getConditionLabels($condition_ids) as $condition_label) {
            $html .= '<li class="alp-ls-map-node__condition-chip">' . htmlspecialchars($condition_label) . '</li>';
        }
        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @param int[] $condition_ids
     * @return string[]
     */
    private function getConditionLabels(array $condition_ids): array
    {
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
}
