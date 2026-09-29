// The Board's drag-to-pan decisions, run by Node's own runner:
//   npm run test:js
//
// Only the pure half is tested here — when a press may pan, when it becomes one, and where the
// scroller goes. The pointer wiring is measured in a real browser by the brief's Playwright run.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    canStartPan,
    PAN_EXCLUDE,
    PAN_THRESHOLD_PX,
    panScrollLeft,
    passedThreshold,
} from '../../resources/js/lib/dragPan.ts';

const background = { pointerType: 'mouse', button: 0, onExcluded: false, onScrollbar: false };

test('a left mouse press on empty background may pan', () => {
    assert.equal(canStartPan(background), true);
});

test('touch and pen keep native scrolling', () => {
    assert.equal(canStartPan({ ...background, pointerType: 'touch' }), false);
    assert.equal(canStartPan({ ...background, pointerType: 'pen' }), false);
});

test('only the left button pans', () => {
    assert.equal(canStartPan({ ...background, button: 1 }), false);
    assert.equal(canStartPan({ ...background, button: 2 }), false);
});

test('a press on a card, control or the scrollbar never pans', () => {
    assert.equal(canStartPan({ ...background, onExcluded: true }), false);
    assert.equal(canStartPan({ ...background, onScrollbar: true }), false);
});

test('the exclusion list keeps every gesture that is already something else', () => {
    for (const selector of ['a', 'button', 'input', '[draggable="true"]', '[data-board-card]', '[role="menuitem"]']) {
        assert.ok(PAN_EXCLUDE.split(', ').includes(selector), `${selector} is excluded`);
    }
});

test('a pan starts only after the threshold, in either direction', () => {
    assert.equal(PAN_THRESHOLD_PX, 4);
    assert.equal(passedThreshold(3, 3), false);
    assert.equal(passedThreshold(4, 0), true);
    assert.equal(passedThreshold(-4, 0), true);
    assert.equal(passedThreshold(0, 4), true);
});

test('the content follows the pointer, with no inertia', () => {
    assert.equal(panScrollLeft(300, -120), 420);
    assert.equal(panScrollLeft(300, 120), 180);
    assert.equal(panScrollLeft(0, 0), 0);
});
