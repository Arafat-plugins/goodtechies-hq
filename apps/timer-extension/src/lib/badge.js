// badge.js — what the toolbar icon's badge shows.
//
// Running → elapsed time as "H:MM" (green); paused → "II" (amber);
// otherwise empty. Elapsed comes from the one formula (formula.js).

import { elapsedSeconds } from './formula.js';

export const RUNNING_COLOR = '#16a34a';
export const PAUSED_COLOR = '#ca8a04';

/** @returns {{text:string, color:string|null}} */
export function badgeFor(state, nowMs, offsetMs) {
    const running = state?.running ?? null;
    if (running && running.state === 'running') {
        const total = Math.max(0, Math.floor(elapsedSeconds(state, nowMs, offsetMs)));
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        return { text: `${h}:${String(m).padStart(2, '0')}`, color: RUNNING_COLOR };
    }
    if (running && running.state === 'paused') {
        return { text: 'II', color: PAUSED_COLOR };
    }
    return { text: '', color: null };
}
