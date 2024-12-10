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

import { describe, it, beforeEach, afterEach } from 'mocha';
import { expect } from 'chai';
import { copyText, showTooltip } from '../../../../src/UI/templates/js/MainControls/src/footer/permalink';

const expectOneCall = () => {
  const expected = [];
  const called = [];

  return {
    callOnce: (proc = () => {}) => {
      const f = (...args) => {
        if (called.includes(f)) {
          throw new Error('Called more than once.');
        }
        called.push(f);
        return proc(...args);
      };
      expected.push(f);

      return f;
    },
    finish: () => expected.forEach(proc => {
      if (!called.includes(proc)) {
        throw new Error('Never called.');
      }
    }),
  };
};

describe('Test permalink copy to clipboard', () => {
  const saved = {};
  beforeEach(() => {
    saved.window = globalThis.window;
    saved.document = globalThis.document;
  });
  afterEach(() => {
    globalThis.window = saved.window;
    globalThis.document = saved.document;
  });

  it('Clipboard API', () => {
    let written = null;
    const response = {};
    const writeText = s => {
      written = s;
      return response;
    };
    globalThis.window = { navigator: { clipboard: { writeText } } };
    expect(copyText('foo')).to.be.equal(response);
    expect(written).to.be.equal('foo');
  });

  it('Legacy Clipboard API', () => {
    const {callOnce, finish} = expectOneCall();
    const node = { remove: callOnce() };
    const range = {
      selectNodeContents: callOnce(n => expect(n).to.be.equal(node))
    };
    const selection = {
      addRange: callOnce(x => expect(x).to.be.equal(range)),
      removeAllRanges: callOnce(),
    };

    globalThis.window = {
      navigator: {},
      getSelection: callOnce(() => selection),
    };

    globalThis.document = {
      createRange: callOnce(() => range),

      createElement: callOnce(text => {
        expect(text).to.be.equal('span');
        return node;
      }),

      execCommand: callOnce(s => {
        expect(s).to.be.equal('copy');
        return true;
      }),

      body: {
        appendChild: callOnce(n => {
          expect(n).to.be.equal(node);
          expect(n.textContent).to.be.equal('foo');
        }),
      },
    };

    return copyText('foo').then(finish);
  });
});

describe('Test permanentlink show tooltip', () => {
  const saved = {};
  beforeEach(() => {
    saved.setTimeout = globalThis.setTimeout;
    saved.document = globalThis.document;
  });
  afterEach(() => {
    globalThis.setTimeout = saved.setTimeout;
    globalThis.document = saved.document;
  });

  const testTooltip = (mainRect, nodeRect, expectTransform = null) => () => {
    const {callOnce, finish} = expectOneCall();
    let callTimeout = null;
    globalThis.document = {
      getElementsByTagName: callOnce(tag => {
        expect(tag).to.be.equal('main');
        return [
          {getBoundingClientRect: callOnce(() => mainRect)}
        ];
      }),
    };

    globalThis.setTimeout = callOnce((proc, delay) => {
      callTimeout = proc;
      expect(delay).to.be.equal(4321);
    });

    const isTooltipClass = name => expect(name).to.be.equal('c-tooltip--visible');
    const node = {
      parentNode: {
        classList: {
          add: callOnce(isTooltipClass),
          remove: callOnce(isTooltipClass),
        },
      },
      getBoundingClientRect: callOnce(() => nodeRect),
      style: {transform: null},
    };
    showTooltip(node, 4321);

    expect(callTimeout).not.to.be.equal(null);
    expect(node.style.transform).to.be.equal(expectTransform);

    callTimeout();
    finish();
  };

  it('Show tooltip', testTooltip({left: 0, right: 10}, {left: 1, right: 9}));
  it('Show tooltip left aligned', testTooltip({left: 5, right: 10}, {left: 3, right: 9}, 'translateX(calc(2px - 50%))'));
  it('Show tooltip right aligned', testTooltip({left: 0, right: 7}, {left: 1, right: 9}, 'translateX(calc(-2px - 50%))'));
});
