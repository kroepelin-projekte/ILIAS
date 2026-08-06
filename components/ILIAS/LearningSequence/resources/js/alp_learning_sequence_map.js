/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

(function (window, document) {
  window.il = window.il || {};
  window.il.LSO = window.il.LSO || {};

  function getNodeMap(root) {
    const nodes = root.querySelectorAll('[data-alp-ls-map-node]');

    return new Map(Array.from(nodes).map((node) => [node.dataset.objId || '', node]));
  }

  function getSuccessors(node) {
    const raw = node.dataset.successors || '';

    if (raw === '') {
      return [];
    }

    return raw
      .split(',')
      .map((value) => value.trim())
      .filter((value) => value !== '');
  }

  function getRelativePoint(rootRect, rect, verticalPosition) {
    return {
      x: (rect.left - rootRect.left) + (rect.width / 2),
      y: verticalPosition === 'top'
        ? rect.top - rootRect.top
        : (rect.bottom - rootRect.top),
    };
  }

  function buildPathD(fromPoint, toPoint) {
    const deltaY = Math.max(32, (toPoint.y - fromPoint.y) * 0.45);

    return `M ${fromPoint.x} ${fromPoint.y} C ${fromPoint.x} ${fromPoint.y + deltaY}, ${toPoint.x} ${toPoint.y - deltaY}, ${toPoint.x} ${toPoint.y}`;
  }

  function drawConnections(root) {
    const svg = root.querySelector('.alp-ls-map__edges');
    if (!svg) {
      return;
    }

    const nodeMap = getNodeMap(root);
    const rootRect = root.getBoundingClientRect();
    const width = Math.max(root.scrollWidth, root.clientWidth);
    const height = Math.max(root.scrollHeight, root.clientHeight);

    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('width', `${width}`);
    svg.setAttribute('height', `${height}`);
    svg.replaceChildren();

    nodeMap.forEach((sourceNode) => {
      const sourceRect = sourceNode.getBoundingClientRect();
      const sourcePoint = getRelativePoint(rootRect, sourceRect, 'bottom');

      getSuccessors(sourceNode).forEach((successorId) => {
        const targetNode = nodeMap.get(successorId);
        if (!targetNode) {
          return;
        }

        const targetRect = targetNode.getBoundingClientRect();
        const targetPoint = getRelativePoint(rootRect, targetRect, 'top');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');

        path.setAttribute('d', buildPathD(sourcePoint, targetPoint));
        path.setAttribute('class', 'alp-ls-map__edge');

        if (sourceNode.dataset.onWalkedPath === '1' && targetNode.dataset.onWalkedPath === '1') {
          path.classList.add('alp-ls-map__edge--walked');
        }

        if (targetNode.dataset.isCurrent === '1') {
          path.classList.add('alp-ls-map__edge--current');
        }

        svg.appendChild(path);
      });
    });
  }

  function redrawAll() {
    document.querySelectorAll('[data-alp-ls-map]').forEach((root) => drawConnections(root));
  }

  window.il.LSO.AdaptiveMap = window.il.LSO.AdaptiveMap || {
    init() {
      redrawAll();

      if (!window.il.LSO.AdaptiveMap.initialized) {
        window.addEventListener('resize', () => redrawAll());
        window.il.LSO.AdaptiveMap.initialized = true;
      }
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.il.LSO.AdaptiveMap.init());
  } else {
    window.il.LSO.AdaptiveMap.init();
  }
}(window, document));
