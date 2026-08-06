<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Adaptive;

/**
 * PROTOTYPE / TEMP
 *
 * Prototype of the "Adaptive Map": a waterfall (top down) graph with branches
 * and merging paths. CSS, JS and HTML are intentionally kept inside this single
 * PHP file, this is a throw away prototype.
 *
 * Layout is done in plain JS (no jQuery, no external lib):
 *  1. layering    -> longest path from the start node (rank assignment)
 *  2. ordering    -> barycenter heuristic + dummy nodes for long edges
 *  3. positioning -> priority/median based x-coordinates, no overlaps
 *  4. edges       -> orthogonal svg paths (down, across, down)
 *
 * The whole canvas is zoomable (wheel + buttons) and pannable (drag).
 */
class LSOAdaptiveMapPrototype
{
    private const NODE_WIDTH = 190;
    private const NODE_HEIGHT = 86;
    private const H_GAP = 26;
    private const V_GAP = 96;

    public function __construct(
        private \ILIAS\UI\Factory $ui_factory,
        private \ILIAS\UI\Renderer $ui_renderer
    ) {
    }

    /**
     * @param array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}|null $graph
     */
    public function render(?array $graph = null): string
    {
        $graph ??= $this->getFakeDataMany();
        $id = 'lso_map_' . substr(md5((string) mt_rand()), 0, 8);

        $json = json_encode(
            $graph + [
                'metrics' => [
                    'node_width' => self::NODE_WIDTH,
                    'node_height' => self::NODE_HEIGHT,
                    'h_gap' => self::H_GAP,
                    'v_gap' => self::V_GAP,
                ]
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );

        $content = $this->getCss()
            . '<div class="lso-map" id="' . $id . '">'
            . '<div class="lso-map__toolbar">' . $this->renderControls($id) . '</div>'
            . '<div class="lso-map__legend">'
            . '<span class="lso-map__legend-item"><i class="lso-map__swatch lso-map__swatch--open"></i>fortfahren möglich</span>'
            . '<span class="lso-map__legend-item"><i class="lso-map__swatch lso-map__swatch--blocked"></i>gesperrt</span>'
            . '<span class="lso-map__legend-item"><i class="lso-map__swatch lso-map__swatch--done"></i>abgeschlossen</span>'
            . '<span class="lso-map__legend-item"><i class="lso-map__swatch lso-map__swatch--current"></i>aktuelle Position</span>'
            . '</div>'
            . '<div class="lso-map__viewport" tabindex="0">'
            . '<div class="lso-map__canvas">'
            . '<svg class="lso-map__edges" xmlns="http://www.w3.org/2000/svg"></svg>'
            . '<div class="lso-map__nodes"></div>'
            . '</div>'
            . '</div>'
            . '</div>'
            . $this->getJs($id, (string) $json);

        $panel = $this->ui_factory->panel()->standard(
            'Lernpfad (Prototyp)', // #ToDo Sprachvariable
            $this->ui_factory->legacy()->content($content)
        );

        return $this->ui_renderer->render($panel);
    }

    private function renderControls(string $map_id): string
    {
        $f = $this->ui_factory;
        $buttons = [
            $f->button()->standard('–', '#')->withAdditionalOnLoadCode(
                fn($id) => $this->bind($id, $map_id, 'zoomBy(-0.15)')
            ),
            $f->button()->standard('+', '#')->withAdditionalOnLoadCode(
                fn($id) => $this->bind($id, $map_id, 'zoomBy(0.15)')
            ),
            $f->button()->standard('100 %', '#')->withAdditionalOnLoadCode(
                fn($id) => $this->bind($id, $map_id, 'resetZoom()')
            ),
            $f->button()->standard('Einpassen', '#')->withAdditionalOnLoadCode(
                fn($id) => $this->bind($id, $map_id, 'fit()')
            ),
            $f->button()->standard('Zu meiner Position', '#')->withAdditionalOnLoadCode(
                fn($id) => $this->bind($id, $map_id, 'focusCurrent()')
            ),
        ];

        return $this->ui_renderer->render($buttons);
    }

    private function bind(string $button_id, string $map_id, string $call): string
    {
        return 'document.getElementById("' . $button_id . '").addEventListener("click", function (e) {'
            . 'e.preventDefault();'
            . 'if (window.LSOAdaptiveMap && window.LSOAdaptiveMap["' . $map_id . '"]) {'
            . 'window.LSOAdaptiveMap["' . $map_id . '"].' . $call . ';'
            . '}});';
    }

    private function getCss(): string
    {
        return <<<CSS
<style>
.lso-map { --lso-map-done: #3c8b3c; --lso-map-current: #1a7bbd; --lso-map-blocked: #b0b0b0; position: relative; }
.lso-map__toolbar { display: flex; flex-wrap: wrap; gap: .25rem; margin-bottom: .5rem; }
.lso-map__legend { display: flex; flex-wrap: wrap; gap: 1rem; font-size: .85em; margin-bottom: .5rem; }
.lso-map__legend-item { display: inline-flex; align-items: center; gap: .35rem; }
.lso-map__swatch { display: inline-block; width: 22px; height: 0; border-top-width: 3px; border-top-style: solid; }
.lso-map__swatch--open { border-top-color: #4d4d4d; }
.lso-map__swatch--blocked { border-top-color: var(--lso-map-blocked); border-top-style: dashed; }
.lso-map__swatch--done { height: 12px; width: 12px; border: 2px solid var(--lso-map-done); border-radius: 2px; }
.lso-map__swatch--current { height: 12px; width: 12px; border: 2px solid var(--lso-map-current);
    border-radius: 2px; box-shadow: 0 0 0 3px rgba(26,123,189,.25); }

.lso-map__viewport { position: relative; overflow: hidden; height: 600px; resize: vertical;
    border: 1px solid #d3d3d3; background-color: #fbfbfb;
    background-image: radial-gradient(#e3e3e3 1px, transparent 1px); background-size: 22px 22px;
    cursor: grab; }
.lso-map__viewport.is-panning { cursor: grabbing; }
.lso-map__canvas { position: absolute; top: 0; left: 0; transform-origin: 0 0; will-change: transform; }
.lso-map__edges { position: absolute; top: 0; left: 0; overflow: visible; pointer-events: none; z-index: 1; }
.lso-map__nodes { position: absolute; top: 0; left: 0; z-index: 2; }

.lso-map__edge { fill: none; stroke: #4d4d4d; stroke-width: 2;
    stroke-linejoin: miter; stroke-linecap: butt; shape-rendering: crispEdges; }
.lso-map__edge--blocked { stroke: var(--lso-map-blocked); stroke-dasharray: 5 4; }
.lso-map__edge--path { stroke: var(--lso-map-current); stroke-width: 3; }
.lso-map__edge-label { font-size: 10px; fill: #666; paint-order: stroke; stroke: #fbfbfb; stroke-width: 3px; }

.lso-map__node { position: absolute; box-sizing: border-box; width: {$this->px(self::NODE_WIDTH)}; height: {$this->px(self::NODE_HEIGHT)};
    display: flex; flex-direction: column; gap: .15rem; padding: .35rem .45rem; overflow: hidden;
    border: 1px solid #c3c3c3; border-radius: 4px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
.lso-map__node--done { border-color: var(--lso-map-done); border-width: 2px; }
.lso-map__node--current { border-color: var(--lso-map-current); border-width: 2px;
    box-shadow: 0 0 0 4px rgba(26,123,189,.22); }
.lso-map__node--blocked { opacity: .65; border-style: dashed; }
.lso-map__node--terminal { background: #f4f7fa; }
.lso-map__node-head { display: flex; align-items: center; gap: .3rem; min-width: 0; }
.lso-map__node-icon { width: 16px; height: 16px; flex: 0 0 16px; }
.lso-map__node-title { font-weight: bold; font-size: .82em; line-height: 1.15;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lso-map__node-desc { font-size: .74em; color: #555; line-height: 1.2; flex: 1 1 auto; overflow: hidden; }
.lso-map__node-foot { display: flex; align-items: center; justify-content: space-between; gap: .25rem; }
.lso-map__node-link { font-size: .74em; }
.lso-map__badge { font-size: .68em; padding: 0 .3rem; border-radius: 8px; background: #eee; color: #444; }
.lso-map__badge--done { background: #e3f1e3; color: #2c6b2c; }
.lso-map__badge--current { background: #e2eff8; color: #14618f; }
.lso-map__badge--blocked { background: #eee; color: #777; }
</style>
CSS;
    }

    private function px(int $value): string
    {
        return $value . 'px';
    }

    private function getJs(string $id, string $json): string
    {
        return <<<JS
<script>
(function () {
    "use strict";

    var root = document.getElementById("{$id}");
    if (!root) {
        return;
    }
    var data = JSON.parse('{$json}');
    var M = data.metrics || { node_width: 190, node_height: 86, h_gap: 26, v_gap: 64 };
    var viewport = root.querySelector(".lso-map__viewport");
    var canvas = root.querySelector(".lso-map__canvas");
    var svg = root.querySelector(".lso-map__edges");
    var layer = root.querySelector(".lso-map__nodes");

    /* ------------------------------------------------ graph helpers */
    var nodes = {};
    var order = [];
    data.nodes.forEach(function (n) {
        nodes[n.id] = { id: n.id, raw: n, out: [], in: [], rank: 0, x: 0, y: 0, dummy: false };
        order.push(n.id);
    });
    var edges = data.edges.filter(function (e) {
        return nodes[e.from] && nodes[e.to];
    });
    edges.forEach(function (e) {
        nodes[e.from].out.push(e);
        nodes[e.to].in.push(e);
    });

    /* 1. ranking: longest path, cycle safe */
    function rank() {
        var indeg = {}, queue = [], i;
        order.forEach(function (k) { indeg[k] = nodes[k].in.length; });
        order.forEach(function (k) { if (indeg[k] === 0) { queue.push(k); } });
        if (queue.length === 0) { queue.push(order[0]); }
        var seen = {}, guard = 0;
        while (queue.length && guard++ < 100000) {
            var id = queue.shift();
            if (seen[id]) { continue; }
            seen[id] = true;
            for (i = 0; i < nodes[id].out.length; i++) {
                var t = nodes[id].out[i].to;
                nodes[t].rank = Math.max(nodes[t].rank, nodes[id].rank + 1);
                if (--indeg[t] <= 0) { queue.push(t); }
            }
        }
        /* end node always at the very bottom */
        var max = 0;
        order.forEach(function (k) { max = Math.max(max, nodes[k].rank); });
        order.forEach(function (k) {
            if (nodes[k].raw.terminal === "end") { nodes[k].rank = max; }
            if (nodes[k].raw.terminal === "start") { nodes[k].rank = 0; }
        });
    }

    /* 2. build layers, split long edges into dummy chains */
    var layers = [];
    var segments = [];
    function build() {
        var max = 0;
        order.forEach(function (k) { max = Math.max(max, nodes[k].rank); });
        for (var r = 0; r <= max; r++) { layers[r] = []; }
        order.forEach(function (k) { layers[nodes[k].rank].push(nodes[k]); });

        var dc = 0;
        edges.forEach(function (e) {
            var a = nodes[e.from], b = nodes[e.to];
            if (b.rank - a.rank <= 1) {
                segments.push({ edge: e, from: a, to: b });
                return;
            }
            var prev = a;
            for (var r = a.rank + 1; r < b.rank; r++) {
                var d = { id: "__d" + (dc++), rank: r, x: 0, y: 0, dummy: true, raw: {}, in: [], out: [] };
                layers[r].push(d);
                segments.push({ edge: e, from: prev, to: d });
                prev = d;
            }
            segments.push({ edge: e, from: prev, to: b });
        });
        segments.forEach(function (s) {
            s.from.out.push({ from: s.from.id, to: s.to.id, __seg: s });
            s.to.in.push({ from: s.from.id, to: s.to.id, __seg: s });
        });
    }

    /* adjacency per direction, precomputed (fast neighbour lookup) */
    var adjUp = {}, adjDown = {};
    function buildAdj() {
        adjUp = {};
        adjDown = {};
        segments.forEach(function (s) {
            (adjDown[s.from.id] = adjDown[s.from.id] || []).push(s.to);
            (adjUp[s.to.id] = adjUp[s.to.id] || []).push(s.from);
        });
    }

    function neighbours(node, dir) {
        return (dir === "up" ? adjUp[node.id] : adjDown[node.id]) || [];
    }

    /* 3. ordering inside layers: weighted median + transpose, keeps the number
       of edge crossings as low as possible (Sugiyama style) */
    function reindex() {
        layers.forEach(function (l) { l.forEach(function (n, i) { n.idx = i; }); });
    }

    function crossings(r) {
        if (r < 0 || r + 1 >= layers.length) { return 0; }
        var idx = {};
        layers[r + 1].forEach(function (n, i) { idx[n.id] = i; });
        var list = [];
        layers[r].forEach(function (n) {
            var ns = [];
            (adjDown[n.id] || []).forEach(function (m) {
                if (idx[m.id] !== undefined) { ns.push(idx[m.id]); }
            });
            ns.sort(function (a, b) { return a - b; });
            list = list.concat(ns);
        });
        var c = 0;
        for (var i = 0; i < list.length; i++) {
            for (var j = i + 1; j < list.length; j++) {
                if (list[i] > list[j]) { c++; }
            }
        }
        return c;
    }

    function totalCrossings() {
        var t = 0;
        for (var r = 0; r < layers.length; r++) { t += crossings(r); }
        return t;
    }

    function median(node, dir) {
        var ns = neighbours(node, dir).map(function (m) { return m.idx; });
        ns.sort(function (a, b) { return a - b; });
        if (!ns.length) { return -1; }
        var m = Math.floor(ns.length / 2);
        if (ns.length % 2 === 1) { return ns[m]; }
        if (ns.length === 2) { return (ns[0] + ns[1]) / 2; }
        var left = ns[m - 1] - ns[0], right = ns[ns.length - 1] - ns[m];
        return (left + right) === 0 ? ns[m] : (ns[m - 1] * right + ns[m] * left) / (left + right);
    }

    function wmedian(dir) {
        for (var i = 0; i < layers.length; i++) {
            var r = (dir === "up") ? i : layers.length - 1 - i;
            layers[r].forEach(function (n) {
                var m = median(n, dir);
                n.med = (m < 0) ? n.idx : m;
            });
            layers[r].sort(function (a, b) { return a.med - b.med; });
            reindex();
        }
    }

    function transpose() {
        var improved = true, guard = 0;
        while (improved && guard++ < 12) {
            improved = false;
            for (var r = 0; r < layers.length; r++) {
                var l = layers[r];
                for (var i = 0; i + 1 < l.length; i++) {
                    var before = crossings(r - 1) + crossings(r);
                    var tmp = l[i]; l[i] = l[i + 1]; l[i + 1] = tmp;
                    reindex();
                    if (crossings(r - 1) + crossings(r) < before) {
                        improved = true;
                    } else {
                        tmp = l[i]; l[i] = l[i + 1]; l[i + 1] = tmp;
                        reindex();
                    }
                }
            }
        }
    }

    function snapshot() { return layers.map(function (l) { return l.slice(); }); }
    function restore(s) { for (var r = 0; r < layers.length; r++) { layers[r] = s[r]; } }

    function ordering() {
        buildAdj();
        reindex();
        var best = snapshot(), bestC = totalCrossings();
        var rounds = segments.length > 600 ? 4 : 12;
        for (var it = 0; it < rounds && bestC > 0; it++) {
            wmedian(it % 2 === 0 ? "up" : "down");
            transpose();
            var c = totalCrossings();
            if (c < bestC) { bestC = c; best = snapshot(); }
        }
        restore(best);
        reindex();
    }

    /* 4. coordinates */
    var width = 0, height = 0;
    function positions() {
        var w = function (n) { return n.dummy ? 1 : M.node_width; };
        layers.forEach(function (l, r) {
            var total = 0;
            l.forEach(function (n) { total += w(n) + M.h_gap; });
            total -= M.h_gap;
            var x = -total / 2;
            l.forEach(function (n) {
                n.x = x;
                n.y = r * (M.node_height + M.v_gap);
                x += w(n) + M.h_gap;
            });
        });
        /* median alignment, a few relaxation passes, keeps boxes untangled */
        for (var it = 0; it < 6; it++) {
            for (var r = 0; r < layers.length; r++) {
                layers[r].forEach(function (n) {
                    var ns = neighbours(n, "up").concat(neighbours(n, "down"));
                    if (!ns.length) { return; }
                    var target = ns.reduce(function (a, m) { return a + m.x + w(m) / 2; }, 0) / ns.length - w(n) / 2;
                    n.x = n.x + (target - n.x) * 0.5;
                });
                /* resolve overlaps left to right */
                var l = layers[r];
                for (var i = 1; i < l.length; i++) {
                    var min = l[i - 1].x + w(l[i - 1]) + M.h_gap;
                    if (l[i].x < min) { l[i].x = min; }
                }
                for (var j = l.length - 2; j >= 0; j--) {
                    var max = l[j + 1].x - w(l[j]) - M.h_gap;
                    if (l[j].x > max) { l[j].x = max; }
                }
            }
        }
        var minX = Infinity, maxX = -Infinity;
        layers.forEach(function (l) {
            l.forEach(function (n) {
                minX = Math.min(minX, n.x);
                maxX = Math.max(maxX, n.x + w(n));
            });
        });
        var shift = 40 - minX;
        layers.forEach(function (l) { l.forEach(function (n) { n.x += shift; n.y += 20; }); });
        var right = 0;
        layers.forEach(function (l) {
            l.forEach(function (n) { right = Math.max(right, n.x + w(n)); });
        });
        width = right + 40;
        height = layers.length * (M.node_height + M.v_gap) + 40;
    }

    /* ------------------------------------------------ rendering */
    function esc(s) {
        var d = document.createElement("div");
        d.textContent = s === undefined || s === null ? "" : String(s);
        return d.innerHTML;
    }

    function drawNodes() {
        var html = "";
        order.forEach(function (k) {
            var n = nodes[k], o = n.raw;
            var cls = ["lso-map__node"];
            if (o.state === "done") { cls.push("lso-map__node--done"); }
            if (o.state === "blocked") { cls.push("lso-map__node--blocked"); }
            if (o.current) { cls.push("lso-map__node--current"); }
            if (o.terminal) { cls.push("lso-map__node--terminal"); }
            var badge = "";
            if (o.current) {
                badge = '<span class="lso-map__badge lso-map__badge--current">hier</span>';
            } else if (o.state === "done") {
                badge = '<span class="lso-map__badge lso-map__badge--done">erledigt</span>';
            } else if (o.state === "blocked") {
                badge = '<span class="lso-map__badge lso-map__badge--blocked">gesperrt</span>';
            }
            html += '<div class="' + cls.join(" ") + '" id="' + esc("{$id}_n_" + o.id) + '"'
                + ' style="left:' + Math.round(n.x) + 'px;top:' + Math.round(n.y) + 'px"'
                + ' title="' + esc(o.title + (o.description ? " – " + o.description : "")) + '">'
                + '<div class="lso-map__node-head">'
                + (o.icon ? '<img class="lso-map__node-icon" src="' + esc(o.icon) + '" alt="">' : "")
                + '<span class="lso-map__node-title">' + esc(o.title) + "</span>"
                + "</div>"
                + '<div class="lso-map__node-desc">' + esc(o.description || "") + "</div>"
                + '<div class="lso-map__node-foot">'
                + (o.href
                    ? '<a class="btn btn-default btn-sm lso-map__node-link" href="' + esc(o.href) + '">Öffnen</a>'
                    : '<span class="lso-map__node-link">&nbsp;</span>')
                + badge
                + "</div>"
                + "</div>";
        });
        layer.innerHTML = html;
    }

    function drawEdges() {
        var parts = [], defs = "";
        var markers = { open: "#4d4d4d", blocked: "#b0b0b0", path: "#1a7bbd" };
        Object.keys(markers).forEach(function (key) {
            defs += '<marker id="{$id}_arrow_' + key + '" viewBox="0 0 10 10" refX="9" refY="5"'
                + ' markerWidth="6" markerHeight="6" orient="auto-start-reverse">'
                + '<path d="M 0 0 L 10 5 L 0 10 z" fill="' + markers[key] + '"/></marker>';
        });
        parts.push("<defs>" + defs + "</defs>");

        /* group segments per original edge so we can draw one polyline */
        var byEdge = new Map();
        segments.forEach(function (s) {
            if (!byEdge.has(s.edge)) { byEdge.set(s.edge, []); }
            byEdge.get(s.edge).push(s);
        });

        /* fan out the attachment points so parallel edges stay apart and never
           overlap a box: docking happens on the box borders only. The docks are
           sorted by the x position of the other end, this way the arrows leave
           and enter a box in the same left to right order as their partners and
           therefore do not cross right below/above a box. */
        var outSegs = {}, inSegs = {};
        segments.forEach(function (s) {
            (outSegs[s.from.id] = outSegs[s.from.id] || []).push(s);
            (inSegs[s.to.id] = inSegs[s.to.id] || []).push(s);
        });
        Object.keys(outSegs).forEach(function (k) {
            outSegs[k].sort(function (a, b) { return a.to.x - b.to.x; });
            outSegs[k].forEach(function (s, i) { s.__oi = i; s.__oc = outSegs[k].length; });
        });
        Object.keys(inSegs).forEach(function (k) {
            inSegs[k].sort(function (a, b) { return a.from.x - b.from.x; });
            inSegs[k].forEach(function (s, i) { s.__ii = i; s.__ic = inSegs[k].length; });
        });
        function dockX(node, count, index) {
            if (node.dummy) { return node.x; }
            var span = M.node_width * 0.7;
            var left = node.x + (M.node_width - span) / 2;
            return left + span * (index + 1) / (count + 1);
        }
        function exitPoint(s) {
            var node = s.from;
            if (node.dummy) { return [node.x, node.y]; }
            return [dockX(node, s.__oc || 1, s.__oi || 0), node.y + M.node_height];
        }
        function entryPoint(s) {
            var node = s.to;
            if (node.dummy) { return [node.x, node.y]; }
            return [dockX(node, s.__ic || 1, s.__ii || 0), node.y];
        }

        /* channel assignment: each horizontal run gets its own lane inside the
           gap between two layers (greedy interval colouring), so horizontal
           lines never lie on top of each other and cross as little as possible */
        var gaps = {};
        segments.forEach(function (s) {
            s.__a = exitPoint(s);
            s.__b = entryPoint(s);
            var r = s.from.rank;
            (gaps[r] = gaps[r] || []).push(s);
        });
        Object.keys(gaps).forEach(function (r) {
            var hs = [];
            gaps[r].forEach(function (s) {
                s.__lane = 0;
                if (Math.round(s.__a[0]) !== Math.round(s.__b[0])) {
                    s.__lo = Math.min(s.__a[0], s.__b[0]);
                    s.__hi = Math.max(s.__a[0], s.__b[0]);
                    hs.push(s);
                }
            });
            /* order the lanes so that no vertical stub has to cross a horizontal
               run: a run whose target stub lies inside another run must be placed
               below it, a run whose source stub lies inside another run above it.
               Solved as a topological sort over these constraints. */
            var n = hs.length, adj = [], deg = [], i, j;
            for (i = 0; i < n; i++) { adj[i] = []; deg[i] = 0; }
            function inside(s, x) { return x > s.__lo + 1 && x < s.__hi - 1; }
            function link(a, b) { adj[a].push(b); deg[b]++; }
            for (i = 0; i < n; i++) {
                for (j = 0; j < n; j++) {
                    if (i === j) { continue; }
                    if (inside(hs[i], hs[j].__b[0])) { link(i, j); }
                    if (inside(hs[i], hs[j].__a[0])) { link(j, i); }
                }
            }
            var queue = [], placed = 0, lane = 0;
            for (i = 0; i < n; i++) { if (deg[i] === 0) { queue.push(i); } }
            while (queue.length) {
                var k = queue.shift();
                hs[k].__lane = lane++;
                placed++;
                adj[k].forEach(function (t) { if (--deg[t] === 0) { queue.push(t); } });
            }
            if (placed < n) { /* cyclic constraints: fall back to span order */
                var rest = [];
                for (i = 0; i < n; i++) { if (deg[i] > 0) { rest.push(hs[i]); } }
                rest.sort(function (a, b) { return (a.__hi - a.__lo) - (b.__hi - b.__lo); });
                rest.forEach(function (s) { s.__lane = lane++; });
            }
            gaps[r].forEach(function (s) { s.__laneCount = Math.max(1, lane); });
        });

        byEdge.forEach(function (segs, e) {
            segs.sort(function (a, b) { return a.from.rank - b.from.rank; });
            /* orthogonal routing: down out of the box, sideways inside the lane
               between two layers (never across a box), down into the target */
            var pts = [];
            segs.forEach(function (s) {
                var a = s.__a, b = s.__b;
                if (pts.length === 0) { pts.push([a[0], a[1]]); } else { pts.push([pts[pts.length - 1][0], a[1]]); }
                if (Math.round(a[0]) !== Math.round(b[0])) {
                    var gap = b[1] - a[1];
                    var n = s.__laneCount || 1;
                    var my = a[1] + Math.max(8, Math.min(gap - 8, gap * (s.__lane + 1) / (n + 1)));
                    pts.push([a[0], my]);
                    pts.push([b[0], my]);
                }
                pts.push([b[0], b[1]]);
            });

            var d = "M " + Math.round(pts[0][0]) + " " + Math.round(pts[0][1]);
            for (var i = 1; i < pts.length; i++) {
                if (Math.round(pts[i][0]) === Math.round(pts[i - 1][0])
                    && Math.round(pts[i][1]) === Math.round(pts[i - 1][1])) { continue; }
                d += " L " + Math.round(pts[i][0]) + " " + Math.round(pts[i][1]);
            }

            var kind = e.passable === false ? "blocked" : (e.on_path ? "path" : "open");
            var cls = "lso-map__edge" + (kind === "blocked" ? " lso-map__edge--blocked" : "")
                + (kind === "path" ? " lso-map__edge--path" : "");
            parts.push('<path class="' + cls + '" d="' + d + '" marker-end="url(#{$id}_arrow_' + kind + ')"/>');

            if (e.label) {
                var mid = pts[Math.floor(pts.length / 2)];
                parts.push('<text class="lso-map__edge-label" x="' + (mid[0] + 6) + '" y="' + (mid[1] - 8) + '">'
                    + esc(e.label) + "</text>");
            }
        });

        svg.setAttribute("width", Math.round(width));
        svg.setAttribute("height", Math.round(height));
        svg.innerHTML = parts.join("");
    }

    /* ------------------------------------------------ zoom & pan */
    var zoom = 1, panX = 0, panY = 0;
    function apply() {
        canvas.style.transform = "translate(" + panX + "px," + panY + "px) scale(" + zoom + ")";
        canvas.style.width = width + "px";
        canvas.style.height = height + "px";
    }
    function clampZoom(z) { return Math.min(2.5, Math.max(0.2, z)); }

    var api = {
        zoomBy: function (delta) {
            var cx = viewport.clientWidth / 2, cy = viewport.clientHeight / 2;
            var nz = clampZoom(zoom + delta);
            panX = cx - (cx - panX) * (nz / zoom);
            panY = cy - (cy - panY) * (nz / zoom);
            zoom = nz;
            apply();
        },
        resetZoom: function () { zoom = 1; panX = 0; panY = 0; apply(); },
        fit: function () {
            var sx = (viewport.clientWidth - 20) / width;
            var sy = (viewport.clientHeight - 20) / height;
            zoom = clampZoom(Math.min(sx, sy));
            panX = (viewport.clientWidth - width * zoom) / 2;
            panY = 10;
            apply();
        },
        focusCurrent: function () {
            var cur = null;
            order.forEach(function (k) { if (nodes[k].raw.current) { cur = nodes[k]; } });
            if (!cur) { return; }
            zoom = clampZoom(1);
            panX = viewport.clientWidth / 2 - (cur.x + M.node_width / 2) * zoom;
            panY = viewport.clientHeight / 2 - (cur.y + M.node_height / 2) * zoom;
            apply();
        }
    };

    viewport.addEventListener("wheel", function (e) {
        if (!e.ctrlKey && Math.abs(e.deltaY) < 1) { return; }
        e.preventDefault();
        var rect = viewport.getBoundingClientRect();
        var mx = e.clientX - rect.left, my = e.clientY - rect.top;
        var nz = clampZoom(zoom * (e.deltaY < 0 ? 1.1 : 0.9));
        panX = mx - (mx - panX) * (nz / zoom);
        panY = my - (my - panY) * (nz / zoom);
        zoom = nz;
        apply();
    }, { passive: false });

    var drag = null;
    viewport.addEventListener("pointerdown", function (e) {
        if (e.target.closest("a")) { return; }
        drag = { x: e.clientX, y: e.clientY, px: panX, py: panY };
        viewport.classList.add("is-panning");
        viewport.setPointerCapture(e.pointerId);
    });
    viewport.addEventListener("pointermove", function (e) {
        if (!drag) { return; }
        panX = drag.px + (e.clientX - drag.x);
        panY = drag.py + (e.clientY - drag.y);
        apply();
    });
    ["pointerup", "pointercancel"].forEach(function (ev) {
        viewport.addEventListener(ev, function () {
            drag = null;
            viewport.classList.remove("is-panning");
        });
    });
    viewport.addEventListener("keydown", function (e) {
        var step = 40;
        if (e.key === "ArrowLeft") { panX += step; } else if (e.key === "ArrowRight") { panX -= step; }
        else if (e.key === "ArrowUp") { panY += step; } else if (e.key === "ArrowDown") { panY -= step; }
        else if (e.key === "+") { api.zoomBy(0.15); return; } else if (e.key === "-") { api.zoomBy(-0.15); return; }
        else { return; }
        e.preventDefault();
        apply();
    });

    rank();
    build();
    ordering();
    positions();
    drawNodes();
    drawEdges();
    apply();
    api.fit();

    window.LSOAdaptiveMap = window.LSOAdaptiveMap || {};
    window.LSOAdaptiveMap["{$id}"] = api;
})();
</script>
JS;
    }

    /**
     * Fake data: start, two branches, a merge, an optional deep dive and the end object.
     *
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function getFakeData(): array
    {
        $icon = fn(string $type): string => 'assets/images/standard/icon_' . $type . '.svg';

        $nodes = [
            ['id' => 'start', 'title' => 'Willkommen', 'description' => 'Einstieg in die Lernsequenz',
                'icon' => $icon('lso'), 'href' => '#', 'state' => 'done', 'terminal' => 'start'],
            ['id' => 'pretest', 'title' => 'Einstiegstest', 'description' => 'Bestimmt deinen Lernpfad',
                'icon' => $icon('tst'), 'href' => '#', 'state' => 'done'],
            ['id' => 'basics_a', 'title' => 'Grundlagen A', 'description' => 'Lernmodul, ca. 20 min',
                'icon' => $icon('lm'), 'href' => '#', 'state' => 'done'],
            ['id' => 'basics_b', 'title' => 'Grundlagen B', 'description' => 'Alternative Vertiefung',
                'icon' => $icon('lm'), 'href' => '#', 'state' => 'blocked'],
            ['id' => 'exercise', 'title' => 'Übung 1', 'description' => 'Abgabe erforderlich',
                'icon' => $icon('exc'), 'href' => '#', 'state' => 'open', 'current' => true],
            ['id' => 'wiki', 'title' => 'Begleitendes Wiki', 'description' => 'Optionales Material',
                'icon' => $icon('wiki'), 'href' => '#', 'state' => 'open'],
            ['id' => 'deep', 'title' => 'Deep Dive', 'description' => 'Nur bei hoher Punktzahl',
                'icon' => $icon('sahs'), 'href' => '#', 'state' => 'blocked'],
            ['id' => 'survey', 'title' => 'Feedback', 'description' => 'Kurze Umfrage',
                'icon' => $icon('svy'), 'href' => '#', 'state' => 'blocked'],
            ['id' => 'final', 'title' => 'Abschlusstest', 'description' => 'Mind. 60 % zum Bestehen',
                'icon' => $icon('tst'), 'href' => '#', 'state' => 'blocked'],
            ['id' => 'end', 'title' => 'Abschluss', 'description' => 'Zertifikat & Ausblick',
                'icon' => $icon('lso'), 'href' => '#', 'state' => 'blocked', 'terminal' => 'end'],
        ];

        $edges = [
            ['from' => 'start', 'to' => 'pretest', 'passable' => true, 'on_path' => true],
            ['from' => 'pretest', 'to' => 'basics_a', 'passable' => true, 'on_path' => true, 'label' => '≥ 50 %'],
            ['from' => 'pretest', 'to' => 'basics_b', 'passable' => false, 'label' => '< 50 %'],
            ['from' => 'basics_a', 'to' => 'exercise', 'passable' => true, 'on_path' => true],
            ['from' => 'basics_b', 'to' => 'exercise', 'passable' => false],
            ['from' => 'basics_a', 'to' => 'wiki', 'passable' => true],
            ['from' => 'exercise', 'to' => 'deep', 'passable' => false, 'label' => 'optional'],
            ['from' => 'exercise', 'to' => 'final', 'passable' => false, 'label' => 'bestanden'],
            ['from' => 'wiki', 'to' => 'final', 'passable' => true],
            ['from' => 'deep', 'to' => 'final', 'passable' => false],
            ['from' => 'final', 'to' => 'survey', 'passable' => false],
            ['from' => 'survey', 'to' => 'end', 'passable' => false],
            ['from' => 'final', 'to' => 'end', 'passable' => false],
        ];

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Fake data with a lot of objects.
     *
     * The generator only connects objects that are neighbours in their layer
     * (split into two, continue, or merge two adjacent paths). Such a graph can
     * always be drawn without crossing arrows, which makes it a good test for
     * the layout.
     *
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function getFakeDataMany(int $count = 35): array
    {
        mt_srand(20250806);
        $icon = fn(string $type): string => 'assets/images/standard/icon_' . $type . '.svg';
        $kinds = [
            ['lm', 'Lernmodul'],
            ['tst', 'Test'],
            ['exc', 'Übung'],
            ['wiki', 'Wiki'],
            ['svy', 'Umfrage'],
            ['sahs', 'Lernpaket'],
            ['file', 'Skript'],
            ['copa', 'Lernseite'],
            ['frm', 'Forum'],
            ['webr', 'Webressource'],
        ];
        $labels = ['≥ 50 %', '< 50 %', 'optional', 'bestanden', 'abgeschlossen', ''];

        $nodes = [[
            'id' => 'n0', 'title' => 'Willkommen', 'description' => 'Einstieg in die Lernsequenz',
            'icon' => $icon('lso'), 'href' => '#', 'state' => 'done', 'terminal' => 'start'
        ]];
        $edges = [];
        $i = 1;
        $done_until = (int) round($count * 0.35);
        $current_at = $done_until + 1;

        $make = function (int $n) use (&$nodes, $kinds, $icon, $done_until, $current_at): string {
            [$type, $name] = $kinds[$n % count($kinds)];
            $state = 'blocked';
            if ($n <= $done_until) {
                $state = 'done';
            } elseif ($n <= $current_at + 2) {
                $state = 'open';
            }
            $nodes[] = [
                'id' => 'n' . $n,
                'title' => $name . ' ' . $n,
                'description' => 'Beschreibung zu ' . $name . ' ' . $n,
                'icon' => $icon($type),
                'href' => '#',
                'state' => $state,
                'current' => $n === $current_at,
            ];
            return 'n' . $n;
        };

        $prev = ['n0'];
        while ($i < $count) {
            $next = [];
            $p = 0;
            while ($p < count($prev) && $i < $count) {
                $roll = mt_rand(0, 99);
                if ($roll < 28 && $p + 1 < count($prev)) {
                    // two adjacent paths merge into one object
                    $id = $make($i++);
                    $edges[] = ['from' => $prev[$p], 'to' => $id, 'passable' => $i <= $current_at];
                    $edges[] = ['from' => $prev[$p + 1], 'to' => $id, 'passable' => $i <= $current_at];
                    $next[] = $id;
                    $p += 2;
                } elseif ($roll < 58 && $i + 1 < $count && count($prev) + count($next) < 7) {
                    // one object branches into two alternatives
                    $a = $make($i++);
                    $b = $make($i++);
                    $edges[] = ['from' => $prev[$p], 'to' => $a, 'passable' => $i <= $current_at,
                        'label' => $labels[mt_rand(0, count($labels) - 1)]];
                    $edges[] = ['from' => $prev[$p], 'to' => $b, 'passable' => false,
                        'label' => $labels[mt_rand(0, count($labels) - 1)]];
                    $next[] = $a;
                    $next[] = $b;
                    $p++;
                } else {
                    // straight continuation
                    $id = $make($i++);
                    $edges[] = ['from' => $prev[$p], 'to' => $id, 'passable' => $i <= $current_at];
                    $next[] = $id;
                    $p++;
                }
            }
            while ($p < count($prev)) {
                $next[] = $prev[$p++];
            }
            $prev = $next;
        }

        $nodes[] = ['id' => 'end', 'title' => 'Abschluss', 'description' => 'Zertifikat & Ausblick',
            'icon' => $icon('lso'), 'href' => '#', 'state' => 'blocked', 'terminal' => 'end'];
        foreach ($prev as $p) {
            $edges[] = ['from' => $p, 'to' => 'end', 'passable' => false];
        }

        // mark the path the learner already walked
        $walked = [];
        foreach ($edges as $k => $e) {
            $from = (int) filter_var($e['from'], FILTER_SANITIZE_NUMBER_INT);
            if ($e['from'] === 'n0' || isset($walked[$e['from']])) {
                if ($from <= $done_until) {
                    $edges[$k]['on_path'] = true;
                    $walked[$e['to']] = true;
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Scale check: generates a graph with the given amount of objects.
     *
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function getFakeDataLarge(int $count = 100): array
    {
        $nodes = [['id' => 'n0', 'title' => 'Start', 'description' => 'Einstieg',
            'href' => '#', 'state' => 'done', 'terminal' => 'start']];
        $edges = [];
        $prev_layer = ['n0'];
        $i = 1;
        while ($i < $count - 1) {
            $branch = 1 + ($i % 3);
            $layer = [];
            for ($b = 0; $b < $branch && $i < $count - 1; $b++, $i++) {
                $id = 'n' . $i;
                $nodes[] = ['id' => $id, 'title' => 'Objekt ' . $i, 'description' => 'Beschreibung ' . $i,
                    'href' => '#', 'state' => $i < 6 ? 'done' : ($i === 6 ? 'open' : 'blocked'),
                    'current' => $i === 6];
                $layer[] = $id;
                foreach ($prev_layer as $p) {
                    $edges[] = ['from' => $p, 'to' => $id, 'passable' => $i < 7];
                }
            }
            $prev_layer = $layer;
        }
        $nodes[] = ['id' => 'end', 'title' => 'Abschluss', 'description' => 'Ende',
            'href' => '#', 'state' => 'blocked', 'terminal' => 'end'];
        foreach ($prev_layer as $p) {
            $edges[] = ['from' => $p, 'to' => 'end', 'passable' => false];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
