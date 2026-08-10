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
 */

(function (window, document) {
  const CONTAINER_SELECTOR = '[data-lso-learning-map]';
  const DEFAULT_METRICS = {
    nodeWidth: 190, nodeHeight: 86, hGap: 26, vGap: 96,
  };
  const DUMMY_WIDTH = 18;
  const MIN_HEIGHT = 280;
  const MAX_HEIGHT = 780;
  const PAD_Y = 24;

  const instances = {};

  /**
   * Escapes text for use in generated markup.
   */

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = (value === undefined || value === null) ? '' : String(value);
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /**
   * Formats translated labels with positional placeholders.
   */

  function fmt(template, args) {
    let i = 0;
    return String(template === undefined ? '' : template)
      .replace(/%(\d+)\$s|%s/g, (match, position) => {
        if (position) {
          return String(args[position - 1]);
        }
        const value = args[i];
        i += 1;
        return String(value);
      });
  }

  /**
   * Creates and registers a learning map in a container.
   */

  function create(root, data) {
    const viewport = root.querySelector('.lso-learning-map__viewport');
    const canvas = root.querySelector('.lso-learning-map__canvas');
    const svg = root.querySelector('.lso-learning-map__edges');
    const layer = root.querySelector('.lso-learning-map__nodes');
    if (!viewport || !canvas || !svg || !layer) {
      return null;
    }

    const { id } = root;
    const rawMetrics = data.metrics || {};
    const rawLabels = data.labels || {};
    const M = {
      nodeWidth: rawMetrics.node_width || DEFAULT_METRICS.nodeWidth,
      nodeHeight: rawMetrics.node_height || DEFAULT_METRICS.nodeHeight,
      hGap: rawMetrics.h_gap || DEFAULT_METRICS.hGap,
      vGap: rawMetrics.v_gap || DEFAULT_METRICS.vGap,
    };
    const labels = {
      ...rawLabels,
      openObject: rawLabels.open_object,
    };

    const horizontal = (data.orientation === 'horizontal');
    const ALONG = horizontal ? M.nodeHeight : M.nodeWidth;
    const DEPTH = horizontal ? M.nodeWidth : M.nodeHeight;
    if (horizontal) {
      root.classList.add('lso-learning-map--horizontal');
    }

    root.style.setProperty('--lso-learning-map-node-width', `${M.nodeWidth}px`);
    root.style.setProperty('--lso-learning-map-node-height', `${M.nodeHeight}px`);

    const nodes = {};
    const order = [];
    (data.nodes || []).forEach((n) => {
      nodes[n.id] = {
        id: n.id, raw: n, out: [], in: [], rank: 0, x: 0, y: 0, sx: 0, sy: 0, dummy: false,
      };
      order.push(n.id);
    });
    if (order.length === 0) {
      return null;
    }
    const edges = (data.edges || []).filter((e) => nodes[e.from] && nodes[e.to]);
    edges.forEach((e) => {
      nodes[e.from].out.push(e);
      nodes[e.to].in.push(e);
      (nodes[e.from].raw.outEdges = nodes[e.from].raw.outEdges || []).push(e);
    });

    /**
   * Assigns nodes to graph layers.
   */

    function rank() {
      const indeg = {};
      const queue = [];
      order.forEach((k) => { indeg[k] = nodes[k].in.length; });
      order.forEach((k) => { if (indeg[k] === 0) { queue.push(k); } });
      if (queue.length === 0) { queue.push(order[0]); }
      const seen = {};
      let guard = 0;
      while (queue.length && guard < 100000) {
        guard += 1;
        const current = queue.shift();
        if (!seen[current]) {
          seen[current] = true;
          for (let i = 0; i < nodes[current].out.length; i += 1) {
            const t = nodes[current].out[i].to;
            nodes[t].rank = Math.max(nodes[t].rank, nodes[current].rank + 1);
            indeg[t] -= 1;
            if (indeg[t] <= 0) { queue.push(t); }
          }
        }
      }
      let max = 0;
      order.forEach((k) => { max = Math.max(max, nodes[k].rank); });
      order.forEach((k) => {
        if (nodes[k].raw.terminal === 'end') { nodes[k].rank = max; }
        if (nodes[k].raw.terminal === 'start') { nodes[k].rank = 0; }
      });
    }

    const layers = [];
    const segments = [];
    /**
     * Builds layout layers and routing segments.
     */
    function build() {
      let max = 0;
      order.forEach((k) => { max = Math.max(max, nodes[k].rank); });
      for (let r = 0; r <= max; r += 1) { layers[r] = []; }
      order.forEach((k) => { layers[nodes[k].rank].push(nodes[k]); });

      let dc = 0;
      edges.forEach((e) => {
        const a = nodes[e.from];
        const b = nodes[e.to];
        if (b.rank - a.rank <= 1) {
          segments.push({ edge: e, from: a, to: b });
          return;
        }
        let prev = a;
        for (let r = a.rank + 1; r < b.rank; r += 1) {
          dc += 1;
          const d = {
            id: `dummy${dc}`, rank: r, x: 0, y: 0, sx: 0, sy: 0, dummy: true, raw: {}, in: [], out: [],
          };
          layers[r].push(d);
          segments.push({ edge: e, from: prev, to: d });
          prev = d;
        }
        segments.push({ edge: e, from: prev, to: b });
      });
      segments.forEach((s) => {
        s.from.out.push({ from: s.from.id, to: s.to.id, seg: s });
        s.to.in.push({ from: s.from.id, to: s.to.id, seg: s });
      });
    }

    let adjUp = {};
    let adjDown = {};
    /**
     * Builds adjacency indexes for graph traversal.
     */
    function buildAdj() {
      adjUp = {};
      adjDown = {};
      segments.forEach((s) => {
        (adjDown[s.from.id] = adjDown[s.from.id] || []).push(s.to);
        (adjUp[s.to.id] = adjUp[s.to.id] || []).push(s.from);
      });
    }

    function neighbours(node, dir) {
      return (dir === 'up' ? adjUp[node.id] : adjDown[node.id]) || [];
    }

    function reindex() {
      layers.forEach((l) => { l.forEach((n, i) => { n.idx = i; }); });
    }

    function crossings(r) {
      if (r < 0 || r + 1 >= layers.length) { return 0; }
      const idx = {};
      layers[r + 1].forEach((n, i) => { idx[n.id] = i; });
      let list = [];
      layers[r].forEach((n) => {
        const ns = [];
        (adjDown[n.id] || []).forEach((m) => {
          if (idx[m.id] !== undefined) { ns.push(idx[m.id]); }
        });
        ns.sort((a, b) => a - b);
        list = list.concat(ns);
      });
      let c = 0;
      for (let i = 0; i < list.length; i += 1) {
        for (let j = i + 1; j < list.length; j += 1) {
          if (list[i] > list[j]) { c += 1; }
        }
      }
      return c;
    }

    function totalCrossings() {
      let t = 0;
      for (let r = 0; r < layers.length; r += 1) { t += crossings(r); }
      return t;
    }

    function median(node, dir) {
      const ns = neighbours(node, dir).map((m) => m.idx);
      ns.sort((a, b) => a - b);
      if (!ns.length) { return -1; }
      const m = Math.floor(ns.length / 2);
      if (ns.length % 2 === 1) { return ns[m]; }
      if (ns.length === 2) { return (ns[0] + ns[1]) / 2; }
      const left = ns[m - 1] - ns[0];
      const right = ns[ns.length - 1] - ns[m];
      return (left + right) === 0 ? ns[m] : (ns[m - 1] * right + ns[m] * left) / (left + right);
    }

    function wmedian(dir) {
      for (let i = 0; i < layers.length; i += 1) {
        const r = (dir === 'up') ? i : layers.length - 1 - i;
        layers[r].forEach((n) => {
          const m = median(n, dir);
          n.med = (m < 0) ? n.idx : m;
        });
        layers[r].sort((a, b) => a.med - b.med);
        reindex();
      }
    }

    function transpose() {
      let improved = true;
      let guard = 0;
      while (improved && guard < 12) {
        guard += 1;
        improved = false;
        for (let r = 0; r < layers.length; r += 1) {
          const l = layers[r];
          for (let i = 0; i + 1 < l.length; i += 1) {
            const before = crossings(r - 1) + crossings(r);
            let tmp = l[i]; l[i] = l[i + 1]; l[i + 1] = tmp;
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

    function snapshot() { return layers.map((l) => l.slice()); }
    function restore(s) { for (let r = 0; r < layers.length; r += 1) { layers[r] = s[r]; } }

    function ordering() {
      buildAdj();
      reindex();
      let best = snapshot();
      let bestC = totalCrossings();
      const rounds = segments.length > 600 ? 4 : 12;
      for (let it = 0; it < rounds && bestC > 0; it += 1) {
        wmedian(it % 2 === 0 ? 'up' : 'down');
        transpose();
        const c = totalCrossings();
        if (c < bestC) { bestC = c; best = snapshot(); }
      }
      restore(best);
      reindex();
    }

    let width = 0;
    let height = 0;
    let alongExtent = 0;
    let depthExtent = 0;
    let contentTop = 0;
    let contentBottom = 0;
    function positions() {
      const w = function (n) { return n.dummy ? DUMMY_WIDTH : ALONG; };
      layers.forEach((l, r) => {
        let total = 0;
        l.forEach((n) => { total += w(n) + M.hGap; });
        total -= M.hGap;
        let x = -total / 2;
        l.forEach((n) => {
          n.x = x;
          n.y = r * (DEPTH + M.vGap);
          x += w(n) + M.hGap;
        });
      });
      for (let it = 0; it < 6; it += 1) {
        for (let r = 0; r < layers.length; r += 1) {
          layers[r].forEach((n) => {
            const ns = neighbours(n, 'up').concat(neighbours(n, 'down'));
            if (!ns.length) { return; }
            const target = ns.reduce((a, m) => a + m.x + (w(m) / 2), 0) / ns.length - (w(n) / 2);
            n.x += (target - n.x) * 0.5;
          });
          const l = layers[r];
          for (let i = 1; i < l.length; i += 1) {
            const min = l[i - 1].x + w(l[i - 1]) + M.hGap;
            if (l[i].x < min) { l[i].x = min; }
          }
          for (let j = l.length - 2; j >= 0; j -= 1) {
            const max = l[j + 1].x - w(l[j]) - M.hGap;
            if (l[j].x > max) { l[j].x = max; }
          }
        }
      }
      let minX = Infinity;
      layers.forEach((l) => {
        l.forEach((n) => { minX = Math.min(minX, n.x); });
      });
      const shift = 40 - minX;
      layers.forEach((l) => { l.forEach((n) => { n.x += shift; n.y += 20; }); });
      let right = 0;
      let bottom = 0;
      layers.forEach((l) => {
        l.forEach((n) => {
          right = Math.max(right, n.x + w(n));
          bottom = Math.max(bottom, n.y + DEPTH);
        });
      });
      alongExtent = right + 40;
      depthExtent = bottom + 20;
    }

    function toScreen() {
      contentTop = Infinity;
      contentBottom = -Infinity;
      layers.forEach((l) => {
        l.forEach((n) => {
          n.sx = horizontal ? n.y : n.x;
          n.sy = horizontal ? n.x : n.y;
          if (n.dummy) { return; }
          contentTop = Math.min(contentTop, n.sy);
          contentBottom = Math.max(contentBottom, n.sy + M.nodeHeight);
        });
      });
      if (!Number.isFinite(contentTop)) { contentTop = 20; contentBottom = 20 + M.nodeHeight; }
      width = horizontal ? depthExtent : alongExtent;
      height = horizontal ? alongExtent : depthExtent;
    }

    function point(p) {
      return horizontal ? [p[1], p[0]] : [p[0], p[1]];
    }

    const live = root.querySelector('[data-lso-learning-map-live]');
    const summary = root.querySelector('[data-lso-learning-map-summary]');

    let announcementsOn = false;
    function announce(message) {
      if (live && announcementsOn) { live.textContent = message; }
    }
    function silently(action) {
      const wasOn = announcementsOn;
      announcementsOn = false;
      action();
      announcementsOn = wasOn;
    }

    function titleOf(nodeId) {
      return (nodes[nodeId] && nodes[nodeId].raw.title) || '';
    }

    function inLearningOrder() {
      const ordered = [];
      layers.forEach((l) => {
        l.forEach((n) => { if (!n.dummy) { ordered.push(n); } });
      });
      return ordered.length ? ordered : order.map((k) => nodes[k]);
    }

    function stateLabel(o) {
      if (o.state === 'done') { return labels.sr_state_done; }
      if (o.state === 'blocked') { return labels.sr_state_blocked; }
      return labels.sr_state_open;
    }

    function successorSentence(node) {
      const parts = [];
      (node.raw.outEdges || []).forEach((e) => {
        const name = titleOf(e.to);
        if (!name) { return; }
        parts.push(e.passable === false ? fmt(labels.sr_blocked_way, [name]) : name);
      });
      if (!parts.length) { return labels.sr_leads_to_none; }
      return fmt(labels.sr_leads_to, [parts.join(', ')]);
    }

    function nodeDescription(node, position, total) {
      const o = node.raw;
      const parts = [fmt(labels.sr_step, [position, total]), stateLabel(o)];
      if (o.current) { parts.push(labels.sr_current); }
      if (o.terminal === 'start') { parts.push(labels.sr_start); }
      if (o.terminal === 'end') { parts.push(labels.sr_end); }
      parts.push(successorSentence(node));
      return parts.join('. ').replace(/\.\./g, '.');
    }

    function writeSummary() {
      if (!summary) { return; }
      const ordered = inLearningOrder();
      const parts = [fmt(labels.sr_summary, [ordered.length])];
      let current = null;
      let first = null;
      let last = null;
      ordered.forEach((n) => {
        if (n.raw.current) { current = n; }
        if (n.raw.terminal === 'start' && !first) { first = n; }
        if (n.raw.terminal === 'end') { last = n; }
      });
      if (first) { parts.push(fmt(labels.sr_summary_start, [first.raw.title])); }
      if (last) { parts.push(fmt(labels.sr_summary_end, [last.raw.title])); }
      if (current) { parts.push(fmt(labels.sr_summary_current, [current.raw.title])); }
      summary.textContent = parts.join(' ');
    }

    function drawNodes() {
      const ordered = inLearningOrder();
      const total = ordered.length;
      let html = '';
      ordered.forEach((n, position) => {
        const o = n.raw;
        const cls = ['lso-learning-map__node'];
        if (o.state === 'done') { cls.push('lso-learning-map__node--done'); }
        if (o.state === 'open') { cls.push('lso-learning-map__node--open'); }
        if (o.state === 'blocked') { cls.push('lso-learning-map__node--blocked'); }
        if (o.current) { cls.push('lso-learning-map__node--current'); }
        if (o.terminal) { cls.push('lso-learning-map__node--terminal'); }
        const stateKey = o.state === 'done' || o.state === 'blocked' ? o.state : 'open';
        let badge = `<span class="lso-learning-map__badge lso-learning-map__badge--${stateKey}"`
          + ` aria-hidden="true">${escapeHtml(labels[stateKey])}</span>`;
        if (o.current) {
          badge = `<span class="lso-learning-map__badge lso-learning-map__badge--current" aria-hidden="true">${
            escapeHtml(labels.current)}</span>${badge}`;
        }
        html += `<li class="${cls.join(' ')}" id="${escapeHtml(`${id}_n_${o.id}`)}"`
          + ` style="left:${Math.round(n.sx)}px;top:${Math.round(n.sy)}px"`
          + ` title="${escapeHtml(o.title + (o.description ? ` \u2013 ${o.description}` : ''))}">`
          + `<span class="lso-learning-map__sr-only">${
            escapeHtml(nodeDescription(n, position + 1, total))}</span>`
          + `<div class="lso-learning-map__node-head">${
            o.icon ? `<img class="lso-learning-map__node-icon" src="${escapeHtml(o.icon)}" alt="">` : ''
          }<span class="lso-learning-map__node-title">${escapeHtml(o.title)}</span>`
          + '</div>'
          + `<div class="lso-learning-map__node-desc">${escapeHtml(o.description || '')}</div>`
          + `<div class="lso-learning-map__node-foot">${
            o.href
              ? `<a class="btn btn-default btn-sm lso-learning-map__node-link" href="${escapeHtml(o.href)}"`
              + ` aria-label="${escapeHtml(`${labels.openObject}: ${o.title}`)}">${
                escapeHtml(labels.openObject)}</a>`
              : '<span class="lso-learning-map__node-link">&nbsp;</span>'
          }${badge
          }</div>`
          + '</li>';
      });
      layer.innerHTML = html;
      writeSummary();
    }

    function drawEdges() {
      const parts = [];
      let defs = '';
      ['open', 'blocked', 'path'].forEach((key) => {
        defs += `<marker id="${id}_arrow_${key}" viewBox="0 0 10 10" refX="9" refY="5"`
          + ' markerWidth="6" markerHeight="6" orient="auto-start-reverse">'
          + `<path class="lso-learning-map__arrow${
            key === 'open' ? '' : ` lso-learning-map__arrow--${key}`
          }" d="M 0 0 L 10 5 L 0 10 z"/></marker>`;
      });
      parts.push(`<defs>${defs}</defs>`);

      const byEdge = new Map();
      segments.forEach((s) => {
        if (!byEdge.has(s.edge)) { byEdge.set(s.edge, []); }
        byEdge.get(s.edge).push(s);
      });

      const outSegs = {};
      const inSegs = {};
      segments.forEach((s) => {
        (outSegs[s.from.id] = outSegs[s.from.id] || []).push(s);
        (inSegs[s.to.id] = inSegs[s.to.id] || []).push(s);
      });
      Object.keys(outSegs).forEach((k) => {
        outSegs[k].sort((a, b) => a.to.x - b.to.x);
        outSegs[k].forEach((s, i) => {
          s.outIndex = i;
          s.outCount = outSegs[k].length;
        });
      });
      Object.keys(inSegs).forEach((k) => {
        inSegs[k].sort((a, b) => a.from.x - b.from.x);
        inSegs[k].forEach((s, i) => {
          s.inIndex = i;
          s.inCount = inSegs[k].length;
        });
      });
      function dockX(node, count, index) {
        if (node.dummy) { return node.x + DUMMY_WIDTH / 2; }
        const span = ALONG * 0.7;
        const left = node.x + ((ALONG - span) / 2);
        return left + ((span * (index + 1)) / (count + 1));
      }
      function exitPoint(s) {
        const node = s.from;
        if (node.dummy) { return [dockX(node), node.y + DEPTH]; }
        return [dockX(node, s.outCount || 1, s.outIndex || 0), node.y + DEPTH];
      }
      function entryPoint(s) {
        const node = s.to;
        if (node.dummy) { return [dockX(node), node.y]; }
        return [dockX(node, s.inCount || 1, s.inIndex || 0), node.y];
      }

      const gaps = {};
      segments.forEach((s) => {
        s.exit = exitPoint(s);
        s.entry = entryPoint(s);
        const r = s.from.rank;
        (gaps[r] = gaps[r] || []).push(s);
      });
      Object.keys(gaps).forEach((r) => {
        const hs = [];
        gaps[r].forEach((s) => {
          s.lane = 0;
          if (Math.round(s.exit[0]) !== Math.round(s.entry[0])) {
            s.lo = Math.min(s.exit[0], s.entry[0]);
            s.hi = Math.max(s.exit[0], s.entry[0]);
            hs.push(s);
          }
        });
        const n = hs.length;
        const adj = [];
        const deg = [];
        let i;
        let j;
        for (i = 0; i < n; i += 1) { adj[i] = []; deg[i] = 0; }
        const inside = (s, x) => x > s.lo + 1 && x < s.hi - 1;
        const link = (a, b) => { adj[a].push(b); deg[b] += 1; };
        for (i = 0; i < n; i += 1) {
          for (j = 0; j < n; j += 1) {
            if (i !== j) {
              if (inside(hs[i], hs[j].entry[0])) { link(i, j); }
              if (inside(hs[i], hs[j].exit[0])) { link(j, i); }
            }
          }
        }
        const queue = [];
        let placed = 0;
        let lane = 0;
        for (i = 0; i < n; i += 1) { if (deg[i] === 0) { queue.push(i); } }
        while (queue.length) {
          const k = queue.shift();
          hs[k].lane = lane;
          lane += 1;
          placed += 1;
          adj[k].forEach((t) => {
            deg[t] -= 1;
            if (deg[t] === 0) { queue.push(t); }
          });
        }
        if (placed < n) {
          const rest = [];
          for (i = 0; i < n; i += 1) { if (deg[i] > 0) { rest.push(hs[i]); } }
          rest.sort((a, b) => (a.hi - a.lo) - (b.hi - b.lo));
          rest.forEach((s) => {
            s.lane = lane;
            lane += 1;
          });
        }
        gaps[r].forEach((s) => { s.laneCount = Math.max(1, lane); });
      });

      byEdge.forEach((segs, e) => {
        segs.sort((a, b) => a.from.rank - b.from.rank);
        const pts = [];
        segs.forEach((s) => {
          const a = s.exit;
          const b = s.entry;
          if (pts.length === 0) {
            pts.push([a[0], a[1]]);
          } else {
            pts.push([pts[pts.length - 1][0], a[1]]);
          }
          if (Math.round(a[0]) !== Math.round(b[0])) {
            const gap = b[1] - a[1];
            const n = s.laneCount || 1;
            const my = a[1] + Math.max(8, Math.min(gap - 8, (gap * (s.lane + 1)) / (n + 1)));
            pts.push([a[0], my]);
            pts.push([b[0], my]);
          }
          pts.push([b[0], b[1]]);
        });

        const sp = pts.map(point);
        let d = `M ${Math.round(sp[0][0])} ${Math.round(sp[0][1])}`;
        for (let i = 1; i < sp.length; i += 1) {
          const same = Math.round(sp[i][0]) === Math.round(sp[i - 1][0])
            && Math.round(sp[i][1]) === Math.round(sp[i - 1][1]);
          if (!same) {
            d += ` L ${Math.round(sp[i][0])} ${Math.round(sp[i][1])}`;
          }
        }

        let kind = 'open';
        if (e.passable === false) {
          kind = 'blocked';
        } else if (e.on_path) {
          kind = 'path';
        }
        const cls = `lso-learning-map__edge${kind === 'blocked' ? ' lso-learning-map__edge--blocked' : ''
        }${kind === 'path' ? ' lso-learning-map__edge--path' : ''}`;
        parts.push(`<path class="${cls}" d="${d}" marker-end="url(#${id}_arrow_${kind})"/>`);

        if (e.label) {
          const mid = sp[Math.floor(sp.length / 2)];
          parts.push(`<text class="lso-learning-map__edge-label" x="${mid[0] + 6}" y="${mid[1] - 8}">${
            escapeHtml(e.label)}</text>`);
        }
      });

      svg.setAttribute('width', Math.round(width));
      svg.setAttribute('height', Math.round(height));
      svg.innerHTML = parts.join('');
    }

    let zoom = 1;
    let panX = 0;
    let panY = 0;
    function apply() {
      clampPan();
      canvas.style.transform = `translate(${panX}px,${panY}px) scale(${zoom})`;
      canvas.style.width = `${width}px`;
      canvas.style.height = `${height}px`;
    }
    function clampZoom(z) { return Math.min(2.5, Math.max(0.2, z)); }
    function contentHeight() { return contentBottom - contentTop; }
    function clampAxis(pan, start, size, view, margin) {
      const px = size * zoom;
      const max = margin - start * zoom;
      const min = view - margin - (start + size) * zoom;
      if (min > max) {
        return (view - px) / 2 - start * zoom;
      }
      return Math.min(max, Math.max(min, pan));
    }
    function clampPan() {
      panX = clampAxis(panX, 0, width, viewport.clientWidth, PAD_Y);
      if (horizontal) {
        panY = (viewport.clientHeight - contentHeight() * zoom) / 2 - contentTop * zoom;
        return;
      }
      panY = clampAxis(panY, contentTop, contentHeight(), viewport.clientHeight, PAD_Y);
    }
    const modalWrapper = root.closest('.lso-learning-map-modal');
    const inModal = modalWrapper !== null;
    function autoHeight() {
      const wanted = Math.round(contentHeight()) + 2 * PAD_Y;
      const min = horizontal ? 0 : MIN_HEIGHT;
      viewport.style.height = `${Math.max(min, Math.min(MAX_HEIGHT, wanted))}px`;
    }

    const api = {
      zoomBy(delta) {
        if (horizontal) { return; }
        const cx = viewport.clientWidth / 2;
        const cy = viewport.clientHeight / 2;
        const nz = clampZoom(zoom + delta);
        panX = cx - (cx - panX) * (nz / zoom);
        panY = cy - (cy - panY) * (nz / zoom);
        zoom = nz;
        apply();
        announce(fmt(labels.sr_zoom, [Math.round(zoom * 100)]));
      },
      resetZoom() {
        if (horizontal) { return; }
        const ch = contentHeight();
        zoom = 1;
        panX = (viewport.clientWidth - width) / 2;
        panY = (viewport.clientHeight - ch) / 2 - contentTop;
        apply();
        announce(fmt(labels.sr_zoom, [100]));
      },
      fit() {
        const ch = contentHeight();
        const sx = (viewport.clientWidth - 2 * PAD_Y) / width;
        const sy = (viewport.clientHeight - 2 * PAD_Y) / ch;
        if (horizontal) {
          zoom = 1;
          panX = Math.max(0, (viewport.clientWidth - width) / 2);
          apply();
          announce(labels.sr_fitted);
          return;
        }
        zoom = clampZoom(Math.min(sx, sy));
        panX = (viewport.clientWidth - width * zoom) / 2;
        panY = (viewport.clientHeight - ch * zoom) / 2 - contentTop * zoom;
        apply();
        announce(labels.sr_fitted);
      },
      focusCurrent() {
        if (horizontal) { return; }
        let cur = null;
        order.forEach((k) => { if (nodes[k].raw.current) { cur = nodes[k]; } });
        if (!cur) {
          announce(labels.sr_no_current);
          return;
        }
        zoom = clampZoom(1);
        panX = viewport.clientWidth / 2 - (cur.sx + M.nodeWidth / 2) * zoom;
        panY = viewport.clientHeight / 2 - (cur.sy + M.nodeHeight / 2) * zoom;
        apply();
        announce(fmt(labels.sr_at_current, [cur.raw.title]));
      },
    };

    viewport.addEventListener('wheel', (e) => {
      let dx = 0;
      if (Math.abs(e.deltaX) >= 1) {
        dx = e.deltaX;
      } else if (e.shiftKey) {
        dx = e.deltaY;
      }
      if (dx !== 0 && !e.ctrlKey) {
        e.preventDefault();
        panX -= dx;
        apply();
        return;
      }
      if (horizontal) { return; }
      if (!e.ctrlKey && Math.abs(e.deltaY) < 1) { return; }
      e.preventDefault();
      const rect = viewport.getBoundingClientRect();
      const mx = e.clientX - rect.left;
      const my = e.clientY - rect.top;
      const nz = clampZoom(zoom * (e.deltaY < 0 ? 1.1 : 0.9));
      panX = mx - (mx - panX) * (nz / zoom);
      panY = my - (my - panY) * (nz / zoom);
      zoom = nz;
      apply();
    }, { passive: false });

    let drag = null;
    viewport.addEventListener('pointerdown', (e) => {
      if (e.target.closest('a')) { return; }
      drag = {
        x: e.clientX, y: e.clientY, px: panX, py: panY,
      };
      viewport.classList.add('is-panning');
      viewport.setPointerCapture(e.pointerId);
    });
    viewport.addEventListener('pointermove', (e) => {
      if (!drag) { return; }
      panX = drag.px + (e.clientX - drag.x);
      panY = drag.py + (e.clientY - drag.y);
      apply();
    });
    ['pointerup', 'pointercancel'].forEach((ev) => {
      viewport.addEventListener(ev, () => {
        drag = null;
        viewport.classList.remove('is-panning');
      });
    });
    viewport.addEventListener('keydown', (e) => {
      const step = 40;
      if (e.key === 'ArrowLeft') {
        panX += step;
      } else if (e.key === 'ArrowRight') {
        panX -= step;
      } else if (e.key === 'ArrowUp') {
        if (horizontal) { return; }
        panY += step;
      } else if (e.key === 'ArrowDown') {
        if (horizontal) { return; }
        panY -= step;
      } else if (e.key === '+') {
        if (horizontal) { return; }
        api.zoomBy(0.15);
        return;
      } else if (e.key === '-') {
        if (horizontal) { return; }
        api.zoomBy(-0.15);
        return;
      } else {
        return;
      }
      e.preventDefault();
      apply();
    });

    rank();
    build();
    ordering();
    positions();
    toScreen();
    drawNodes();
    drawEdges();
    autoHeight();
    apply();
    silently(api.fit);
    announcementsOn = true;

    if (window.ResizeObserver) {
      let lastW = viewport.clientWidth;
      let lastH = viewport.clientHeight;
      const observer = new window.ResizeObserver(() => {
        const w = viewport.clientWidth;
        const h = viewport.clientHeight;
        if (w === 0 || (w === lastW && h === lastH)) { return; }
        const wasHidden = lastW === 0;
        lastW = w;
        lastH = h;
        if (wasHidden) {
          autoHeight();
          silently(api.fit);
          return;
        }
        if (inModal) {
          silently(api.fit);
          return;
        }
        apply();
      });
      observer.observe(viewport);
    }

    if (inModal) {
      window.addEventListener('resize', () => {
        silently(api.fit);
      });
    }

    return api;
  }

  function init(root) {
    if (!root || root.dataset.lsoLearningMapInitialised === 'true') {
      return;
    }
    const source = root.querySelector('[data-lso-learning-map-data]');
    if (!source) {
      return;
    }

    let data;
    try {
      data = JSON.parse(source.textContent);
    } catch (e) {
      return;
    }

    const api = create(root, data);
    if (!api) {
      return;
    }
    root.dataset.lsoLearningMapInitialised = 'true';
    instances[root.id] = api;
  }

  function initAll(context) {
    (context || document).querySelectorAll(CONTAINER_SELECTOR).forEach(init);
  }

  window.il = window.il || {};
  window.il.LSO = window.il.LSO || {};
  window.il.LSO.LearningMap = {
    init,
    initAll,
    get(mapId) {
      return instances[mapId] || null;
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { initAll(); });
  } else {
    initAll();
  }
}(window, document));
