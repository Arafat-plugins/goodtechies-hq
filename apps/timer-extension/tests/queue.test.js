import { test } from 'node:test';
import assert from 'node:assert/strict';
import { enqueue, take, drop, size, MAX_PER_ENTRY } from '../src/lib/queue.js';

const T0 = Date.parse('2026-10-05T00:00:00Z');
const sample = (i, state = 'active') => ({
    minute: new Date(T0 + i * 60_000).toISOString(),
    state,
    call_source: null,
    sites: [],
});

test('enqueue replaces the same minute and keeps order', () => {
    let q = {};
    q = enqueue(q, 7, sample(0));
    q = enqueue(q, 7, sample(1));
    q = enqueue(q, 7, sample(0, 'idle'));
    assert.deepEqual(q['7'].map((s) => s.state), ['idle', 'active']);
    assert.equal(q['7'][0].minute, sample(0).minute);
    assert.equal(size(q), 2);
});

test('enqueue does not mutate its input', () => {
    const q = { 7: [sample(0)] };
    const next = enqueue(q, 7, sample(1));
    assert.equal(q['7'].length, 1);
    assert.equal(next['7'].length, 2);
});

test('enqueue caps at 1440 per entry (oldest dropped)', () => {
    let q = {};
    for (let i = 0; i < MAX_PER_ENTRY + 5; i += 1) {
        q = enqueue(q, 7, sample(i));
    }
    assert.equal(MAX_PER_ENTRY, 1440);
    assert.equal(q['7'].length, 1440);
    assert.equal(q['7'][0].minute, sample(5).minute);
});

test('take(120) ordering: oldest first, rest kept', () => {
    let q = {};
    for (let i = 0; i < 130; i += 1) {
        q = enqueue(q, 7, sample(i));
    }
    const { batch, rest } = take(q, 7, 120);
    assert.equal(batch.length, 120);
    assert.equal(batch[0].minute, sample(0).minute);
    assert.equal(batch[119].minute, sample(119).minute);
    assert.equal(rest['7'].length, 10);
    assert.equal(rest['7'][0].minute, sample(120).minute);

    const last = take(rest, 7);
    assert.equal(last.batch.length, 10);
    assert.equal(last.rest['7'], undefined);
});

test('drop removes one entry only; size counts all', () => {
    let q = {};
    q = enqueue(q, 7, sample(0));
    q = enqueue(q, 8, sample(0));
    q = enqueue(q, 8, sample(1));
    assert.equal(size(q), 3);
    const after = drop(q, 8);
    assert.deepEqual(Object.keys(after), ['7']);
    assert.equal(size(after), 1);
    assert.equal(size(q), 3);
});
