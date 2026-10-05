import { test } from 'node:test';
import assert from 'node:assert/strict';
import { idleClock, exemptNow } from '../src/lib/idleClock.js';

const T0 = 1_759_640_400_000;
const opts = { promptAfterMs: 120_000, pauseAfterMs: 300_000 };

test('prompt at exactly 120 s, not at 119 s', () => {
    const at119 = idleClock({ lastInputAt: T0, lastExemptAt: 0, lockedAt: null, now: T0 + 119_000, ...opts });
    assert.equal(at119.promptDue, false);
    assert.equal(at119.idleMs, 119_000);

    const at120 = idleClock({ lastInputAt: T0, lastExemptAt: 0, lockedAt: null, now: T0 + 120_000, ...opts });
    assert.equal(at120.promptDue, true);
    assert.equal(at120.idleSince, T0);
});

test('no prompt while locked', () => {
    const clock = idleClock({ lastInputAt: T0, lastExemptAt: 0, lockedAt: T0 + 10_000, now: T0 + 600_000, ...opts });
    assert.equal(clock.promptDue, false);
    assert.equal(clock.idleMs, 600_000);
});

test('exemption at 90 s restarts the clock (prompt 120 s after the exemption ended)', () => {
    const exemptEnd = T0 + 90_000;
    // 120 s after the original input: not due, because the exemption held until 90 s.
    const early = idleClock({ lastInputAt: T0, lastExemptAt: exemptEnd, lockedAt: null, now: T0 + 120_000, ...opts });
    assert.equal(early.promptDue, false);
    assert.equal(early.idleSince, exemptEnd);

    const due = idleClock({ lastInputAt: T0, lastExemptAt: exemptEnd, lockedAt: null, now: exemptEnd + 120_000, ...opts });
    assert.equal(due.promptDue, true);
});

test('pauseAt = idleSince + 300 s', () => {
    const clock = idleClock({ lastInputAt: T0, lastExemptAt: null, lockedAt: null, now: T0 + 5_000, ...opts });
    assert.equal(clock.pauseAt, T0 + 300_000);
});

test('idleMs never negative; missing timestamps count as 0', () => {
    const clock = idleClock({ lastInputAt: T0 + 5_000, lastExemptAt: undefined, lockedAt: null, now: T0, ...opts });
    assert.equal(clock.idleMs, 0);
    assert.equal(clock.promptDue, false);
});

test('unknown input time never prompts', () => {
    const now = T0 + 3_600_000;
    for (const unknown of [{ lastInputAt: 0, lastExemptAt: 0 }, { lastInputAt: null, lastExemptAt: undefined }]) {
        const clock = idleClock({ ...unknown, lockedAt: null, now, ...opts });
        assert.deepEqual(clock, { idleSince: now, idleMs: 0, promptDue: false, pauseAt: now + 300_000 });
    }
});

test('exemptNow only for media/call', () => {
    assert.equal(exemptNow(5, { state: 'media', now: 10 }), 10);
    assert.equal(exemptNow(5, { state: 'call', now: 10 }), 10);
    assert.equal(exemptNow(5, { state: 'active', now: 10 }), 5);
    assert.equal(exemptNow(5, { state: 'idle', now: 10 }), 5);
});
