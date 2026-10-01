// Brief 010: the DM header's "last seen …" line (`lastSeenText`) and the polling build's
// "online = seen within two minutes" rule (`recentlySeen`), run by Node's own runner.
//
// The process is pinned to Dhaka before anything reads a date, so "today" and "yesterday" are
// decided in a fixed zone whatever machine runs this.
process.env.TZ = 'Asia/Dhaka';

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { lastSeenText, recentlySeen } from '../../resources/js/Components/Messages/presence.ts';

// 1 Oct 2026, 15:30 in Dhaka (UTC+6).
const NOW = new Date('2026-10-01T09:30:00Z');

test('no time at all reads as a long time ago', () => {
    assert.equal(lastSeenText(null, NOW), 'last seen a long time ago');
    assert.equal(lastSeenText('not a date', NOW), 'last seen a long time ago');
});

test('under a minute is just now', () => {
    assert.equal(lastSeenText('2026-10-01T09:29:30Z', NOW), 'last seen just now');
});

test('under an hour counts minutes', () => {
    assert.equal(lastSeenText('2026-10-01T09:25:00Z', NOW), 'last seen 5 min ago');
    assert.equal(lastSeenText('2026-10-01T08:31:00Z', NOW), 'last seen 59 min ago');
});

test('earlier today says the clock', () => {
    // 14:02 Dhaka.
    assert.equal(lastSeenText('2026-10-01T08:02:00Z', NOW), 'last seen today at 14:02');
});

test('yesterday says yesterday and the clock', () => {
    // 30 Sep, 09:10 Dhaka.
    assert.equal(lastSeenText('2026-09-30T03:10:00Z', NOW), 'last seen yesterday at 09:10');
});

test('older says the date, and the year only when it is another year', () => {
    assert.equal(lastSeenText('2026-09-28T06:00:00Z', NOW), 'last seen 28 Sep');
    assert.equal(lastSeenText('2025-12-31T06:00:00Z', NOW), 'last seen 31 Dec 2025');
});

test('the day boundary is the local one, not UTC', () => {
    // 23:30 UTC on 30 Sep is 05:30 on 1 Oct in Dhaka — today, not yesterday.
    assert.equal(lastSeenText('2026-09-30T23:30:00Z', NOW), 'last seen today at 05:30');
});

test('online on a polling build is seen within two minutes', () => {
    const now = NOW.getTime();

    assert.equal(recentlySeen('2026-10-01T09:28:30Z', now), true);
    assert.equal(recentlySeen('2026-10-01T09:28:00Z', now), false);
    assert.equal(recentlySeen(null, now), false);
});
