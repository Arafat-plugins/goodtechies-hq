import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseMediaMessage, parseCommand, COMMANDS } from '../src/lib/messages.js';

test('the valid media message passes', () => {
    assert.deepEqual(
        parseMediaMessage({ type: 'gt-media', videoPlaying: true, streamLive: false }),
        { videoPlaying: true, streamLive: false },
    );
});

test('extra key (url) → null', () => {
    assert.equal(parseMediaMessage({ type: 'gt-media', videoPlaying: true, streamLive: false, url: 'https://a.com/x' }), null);
});

test('missing key → null', () => {
    assert.equal(parseMediaMessage({ type: 'gt-media', videoPlaying: true }), null);
});

test('non-boolean → null', () => {
    assert.equal(parseMediaMessage({ type: 'gt-media', videoPlaying: 'true', streamLive: false }), null);
    assert.equal(parseMediaMessage({ type: 'gt-media', videoPlaying: true, streamLive: 1 }), null);
});

test('wrong type → null', () => {
    assert.equal(parseMediaMessage({ type: 'gt-cmd', videoPlaying: true, streamLive: false }), null);
    assert.equal(parseMediaMessage(null), null);
    assert.equal(parseMediaMessage('gt-media'), null);
    assert.equal(parseMediaMessage([true, false]), null);
});

test('parseCommand accepts only the listed cmds', () => {
    for (const cmd of COMMANDS) {
        assert.deepEqual(parseCommand({ type: 'gt-cmd', cmd }), { cmd, payload: {} });
    }
    assert.deepEqual(parseCommand({ type: 'gt-cmd', cmd: 'start', payload: { task_id: 4 } }), { cmd: 'start', payload: { task_id: 4 } });
    assert.equal(parseCommand({ type: 'gt-cmd', cmd: 'deleteEverything' }), null);
    assert.equal(parseCommand({ type: 'gt-cmd', cmd: 'toString' }), null);
    assert.equal(parseCommand({ type: 'gt-media', cmd: 'start' }), null);
    assert.equal(parseCommand({ type: 'gt-cmd', cmd: 'start', payload: 'x' }), null);
    assert.equal(parseCommand({ type: 'gt-cmd', cmd: 'start', payload: [1] }), null);
});
