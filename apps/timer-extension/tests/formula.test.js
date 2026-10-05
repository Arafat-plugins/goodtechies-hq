import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clockOffset, elapsedSeconds, leftTodaySeconds, formatHms, formatDuration } from '../src/lib/formula.js';

const SERVER_TIME = '2026-10-05T09:41:12+06:00';
const SERVER_MS = Date.parse(SERVER_TIME);

function state(runState = 'running', elapsed = 600, target = 18000) {
    return {
        server_time: SERVER_TIME,
        running: { id: 1, state: runState, elapsed_seconds: elapsed },
        today: { date: '2026-10-05', counted_seconds: 9120, pending_seconds: 0, target_seconds: target },
    };
}

test('elapsedSeconds with a 5 s skewed clock equals the server-based value within 1 s', () => {
    // Our clock is 5 s behind the server.
    const receivedAtLocal = SERVER_MS - 5000;
    const offset = clockOffset(SERVER_TIME, receivedAtLocal);
    assert.equal(offset, 5000);
    // 30 s later on our clock.
    const nowLocal = receivedAtLocal + 30_000;
    const value = elapsedSeconds(state(), nowLocal, offset);
    assert.ok(Math.abs(value - (600 + 30)) <= 1, `got ${value}`);
});

test('paused → frozen', () => {
    const s = state('paused', 600);
    assert.equal(elapsedSeconds(s, SERVER_MS + 3_600_000, 0), 600);
});

test('no running entry → 0', () => {
    assert.equal(elapsedSeconds({ server_time: SERVER_TIME, running: null }, SERVER_MS, 0), 0);
});

test('leftTodaySeconds = 18000 − (9120 + elapsed)', () => {
    assert.equal(leftTodaySeconds(state('paused', 600), SERVER_MS, 0), 18000 - (9120 + 600));
    assert.equal(leftTodaySeconds(state('running', 600), SERVER_MS + 60_000, 0), 18000 - (9120 + 660));
});

test('leftTodaySeconds is never negative', () => {
    assert.equal(leftTodaySeconds(state('running', 20000), SERVER_MS, 0), 0);
});

test('leftTodaySeconds is null without a target', () => {
    assert.equal(leftTodaySeconds(state('running', 600, null), SERVER_MS, 0), null);
});

test('formatHms samples', () => {
    assert.equal(formatHms(0), '0:00:00');
    assert.equal(formatHms(59.9), '0:00:59');
    assert.equal(formatHms(3725), '1:02:05');
    assert.equal(formatHms(36000), '10:00:00');
    assert.equal(formatHms(-5), '0:00:00');
});

test('formatDuration samples', () => {
    assert.equal(formatDuration(15480), '4h 18m');
    assert.equal(formatDuration(1080), '18m');
    assert.equal(formatDuration(59), '0m');
    assert.equal(formatDuration(3600), '1h 0m');
});
