// messages.js — validation of every message the worker receives.
//
// Content scripts may post ONLY {type: 'gt-media', videoPlaying, streamLive}
// with two booleans and no other key (docs/extension-api.md §9). Anything
// else is dropped. The extension's own pages (D4) send {type: 'gt-cmd', cmd, payload?}.

/** The commands the worker's handleCommand understands. */
export const COMMANDS = [
    'getState',
    'sync',
    'getTasks',
    'start',
    'pause',
    'resume',
    'stop',
    'idleDecision',
    'meeting',
    'pair',
    'disconnect',
    'setServer',
];

const MEDIA_KEYS = ['streamLive', 'type', 'videoPlaying'];

/** A plain object: not null, not an array, created by {} or Object.create(null). */
function isPlainObject(value) {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return false;
    }
    const proto = Object.getPrototypeOf(value);
    return proto === Object.prototype || proto === null;
}

/** {videoPlaying, streamLive} for a valid media message, otherwise null. */
export function parseMediaMessage(msg) {
    if (!isPlainObject(msg)) {
        return null;
    }
    const keys = Object.keys(msg).sort();
    if (keys.length !== MEDIA_KEYS.length || keys.some((k, i) => k !== MEDIA_KEYS[i])) {
        return null;
    }
    if (msg.type !== 'gt-media') {
        return null;
    }
    if (typeof msg.videoPlaying !== 'boolean' || typeof msg.streamLive !== 'boolean') {
        return null;
    }
    return { videoPlaying: msg.videoPlaying, streamLive: msg.streamLive };
}

/** {cmd, payload} for a valid command from an extension page, otherwise null. */
export function parseCommand(msg) {
    if (!isPlainObject(msg) || msg.type !== 'gt-cmd') {
        return null;
    }
    if (typeof msg.cmd !== 'string' || !COMMANDS.includes(msg.cmd)) {
        return null;
    }
    if (msg.payload !== undefined && !isPlainObject(msg.payload)) {
        return null;
    }
    return { cmd: msg.cmd, payload: msg.payload ?? {} };
}
