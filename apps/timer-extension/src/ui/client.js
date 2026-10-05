// client.js — the one way the extension's pages talk to the worker.
//
// Pages never call the server themselves: every action is a
// {type: 'gt-cmd', cmd, payload} message answered by the worker's handleCommand().

import { formatHms, formatDuration, elapsedSeconds, leftTodaySeconds, clockOffset } from '../lib/formula.js';

/** The shared timer formula and formatting (docs/extension-api.md §4), re-exported. */
export const fmt = Object.freeze({ formatHms, formatDuration, elapsedSeconds, leftTodaySeconds, clockOffset });

/** Send one command to the worker and resolve its reply. */
export function send(cmd, payload) {
    const msg = { type: 'gt-cmd', cmd };
    if (payload !== undefined) {
        msg.payload = payload;
    }
    return chrome.runtime.sendMessage(msg);
}

/** setInterval that is cleared when the page goes away. Returns the interval id. */
export function every(ms, fn) {
    const id = setInterval(fn, ms);
    addEventListener('pagehide', () => clearInterval(id), { once: true });
    return id;
}
