// The user's R3 table: what one minute counts as.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { classify, callSource } from '../src/lib/classifier.js';

const NOW = 1_759_640_472_000;
const base = { idleState: 'idle', audibleTabs: false, videoPlaying: false, streamLive: false, manualMeetingUntil: null, now: NOW };

test('active: keyboard or mouse input → active', () => {
    assert.equal(classify({ ...base, idleState: 'active' }), 'active');
    assert.equal(classify({ ...base, idleState: 'active', audibleTabs: true, streamLive: true }), 'active');
});

test('media: audible tab only', () => {
    assert.equal(classify({ ...base, audibleTabs: true }), 'media');
});

test('media: video playing only', () => {
    assert.equal(classify({ ...base, videoPlaying: true }), 'media');
});

test('media: audible tab and video', () => {
    assert.equal(classify({ ...base, audibleTabs: true, videoPlaying: true }), 'media');
});

test('call: live stream only', () => {
    assert.equal(classify({ ...base, streamLive: true }), 'call');
    assert.equal(callSource({ streamLive: true, manualMeetingUntil: null, now: NOW }), 'detected');
});

test('call: manual meeting only', () => {
    const input = { ...base, manualMeetingUntil: NOW + 60_000 };
    assert.equal(classify(input), 'call');
    assert.equal(callSource(input), 'manual');
});

test('call: stream while audible → call', () => {
    assert.equal(classify({ ...base, streamLive: true, audibleTabs: true, videoPlaying: true }), 'call');
});

test('call: muted mic (detector reports streamLive true) → call', () => {
    // A muted track is still readyState "live", so stream-hook reports live: true.
    assert.equal(classify({ ...base, streamLive: true, audibleTabs: false }), 'call');
});

test('call source: stream wins over manual meeting; none → null', () => {
    assert.equal(callSource({ streamLive: true, manualMeetingUntil: NOW + 1000, now: NOW }), 'detected');
    assert.equal(callSource({ streamLive: false, manualMeetingUntil: null, now: NOW }), null);
});

test('idle: nothing at all', () => {
    assert.equal(classify(base), 'idle');
});

test('idle: locked wins over stream and audible', () => {
    assert.equal(classify({ ...base, idleState: 'locked', streamLive: true, audibleTabs: true, videoPlaying: true }), 'idle');
    assert.equal(classify({ ...base, idleState: 'locked', manualMeetingUntil: NOW + 60_000 }), 'idle');
});

test('idle: manual meeting expired', () => {
    assert.equal(classify({ ...base, manualMeetingUntil: NOW - 1 }), 'idle');
    assert.equal(classify({ ...base, manualMeetingUntil: NOW }), 'idle');
    assert.equal(callSource({ streamLive: false, manualMeetingUntil: NOW - 1, now: NOW }), null);
});

test('idle: non-boolean inputs treated false', () => {
    assert.equal(classify({ ...base, audibleTabs: 'true', videoPlaying: 1, streamLive: 'yes' }), 'idle');
    assert.equal(classify({ ...base, streamLive: {} }), 'idle');
    assert.equal(callSource({ streamLive: 1, manualMeetingUntil: '9999999999999', now: NOW }), null);
});

test('idle: unknown idleState treated as idle', () => {
    assert.equal(classify({ ...base, idleState: 'sleeping' }), 'idle');
    assert.equal(classify({ ...base, idleState: undefined, audibleTabs: true }), 'media');
});
