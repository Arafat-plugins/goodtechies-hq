// classifier.js — decides what one minute of tracked time was.
//
// Pure: no chrome.*, no storage. The worker gathers the inputs once a minute
// and calls classify(); the result becomes a Sample's `state`
// (docs/extension-api.md §5).
//
// Order matters (first match wins):
//   1. screen locked            → idle   (nobody is at the machine)
//   2. recent keyboard/mouse    → active
//   3. live mic/camera stream,
//      or "I'm in a meeting"    → call
//   4. audible tab or a playing
//      video                    → media
//   5. otherwise                → idle

const IDLE_STATES = ['active', 'idle', 'locked'];

/** A value that must be a boolean: anything that is not exactly `true` is false. */
function bool(value) {
    return value === true;
}

/** chrome.idle states are active|idle|locked; anything unknown is treated as idle. */
function normaliseIdleState(idleState) {
    return IDLE_STATES.includes(idleState) ? idleState : 'idle';
}

/** True while a manual meeting ("I'm in a meeting") is still running at `now`. */
function manualMeetingActive(manualMeetingUntil, now) {
    return typeof manualMeetingUntil === 'number'
        && Number.isFinite(manualMeetingUntil)
        && typeof now === 'number'
        && now < manualMeetingUntil;
}

/**
 * Classify one minute.
 * @returns {'active'|'media'|'call'|'idle'}
 */
export function classify({ idleState, audibleTabs, videoPlaying, streamLive, manualMeetingUntil, now } = {}) {
    const idle = normaliseIdleState(idleState);

    if (idle === 'locked') {
        return 'idle';
    }
    if (idle === 'active') {
        return 'active';
    }
    if (bool(streamLive) || manualMeetingActive(manualMeetingUntil, now)) {
        return 'call';
    }
    if (bool(audibleTabs) || bool(videoPlaying)) {
        return 'media';
    }
    return 'idle';
}

/**
 * Where a `call` minute came from: a detected mic/camera stream wins over the
 * manual meeting button. Null when neither holds.
 * @returns {'detected'|'manual'|null}
 */
export function callSource({ streamLive, manualMeetingUntil, now } = {}) {
    if (bool(streamLive)) {
        return 'detected';
    }
    if (manualMeetingActive(manualMeetingUntil, now)) {
        return 'manual';
    }
    return null;
}
