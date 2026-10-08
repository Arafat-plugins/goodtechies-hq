// Polish 043: the chat image viewer's zoom maths. Run by Node's own runner (`npm run test:js`).
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { clampPan, clampScale, IDENTITY, MAX_SCALE, wheelFactor, zoomAround } from '../../resources/js/Components/Messages/lightboxZoom.ts';

test('clampScale keeps the zoom between 1× and the maximum', () => {
    assert.equal(clampScale(0.3), 1);
    assert.equal(clampScale(2), 2);
    assert.equal(clampScale(99), MAX_SCALE);
});

test('zoomAround keeps the point under the cursor in place', () => {
    const at = { x: 100, y: -50 };
    const zoomed = zoomAround(IDENTITY, 2, at);

    assert.deepEqual(zoomed, { scale: 2, x: -100, y: 50 });
    // The picture's point that was under the cursor: (at - t) / s, before and after.
    assert.deepEqual({ x: (at.x - zoomed.x) / zoomed.scale, y: (at.y - zoomed.y) / zoomed.scale }, { x: 100, y: -50 });
});

test('zoomAround back to 1× re-centres the picture', () => {
    assert.deepEqual(zoomAround({ scale: 3, x: 120, y: -40 }, 0.5, { x: 10, y: 10 }), IDENTITY);
});

test('clampPan stops the picture at the stage edges and centres a smaller axis', () => {
    const image = { width: 800, height: 400 };
    const stage = { width: 1000, height: 600 };

    // 2×: 1600×800 in a 1000×600 stage → up to 300 px sideways, 100 px up/down.
    assert.deepEqual(clampPan({ scale: 2, x: 900, y: -500 }, image, stage), { scale: 2, x: 300, y: -100 });
    // 1×: smaller than the stage on both axes → centred.
    assert.deepEqual(clampPan({ scale: 1, x: 50, y: 50 }, image, stage), { scale: 1, x: 0, y: 0 });
});

test('wheelFactor zooms in on wheel-up, out on wheel-down, capped per notch', () => {
    assert.ok(wheelFactor(-100) > 1);
    assert.ok(wheelFactor(100) < 1);
    assert.equal(wheelFactor(-1000), wheelFactor(-120));
});
