// idleClock.js — the pure idle clock (docs/extension-api.md §6).
//
// The extension owns the "Are you still working?" prompt. A person counts as
// idle from the later of: their last keyboard/mouse input, and the last moment
// a media/call/manual-meeting exemption was true. So watching a video or being
// in a call keeps the clock from running; when the exemption ends, the clock
// starts from that moment, not from the original input.

/**
 * @param {object} p
 * @param {number|null} p.lastInputAt   ms epoch of the last seen input
 * @param {number|null} p.lastExemptAt  ms epoch of the last exempt minute
 * @param {number|null} p.lockedAt      ms epoch the screen locked, null when unlocked
 * @param {number} p.now                ms epoch
 * @param {number} p.promptAfterMs      settings.idle_prompt_seconds × 1000
 * @param {number} p.pauseAfterMs       settings.idle_pause_minutes × 60000
 * @returns {{idleSince:number, idleMs:number, promptDue:boolean, pauseAt:number}}
 */
export function idleClock({ lastInputAt, lastExemptAt, lockedAt, now, promptAfterMs, pauseAfterMs }) {
    const idleSince = Math.max(lastInputAt ?? 0, lastExemptAt ?? 0);
    // Neither time is known yet (fresh install): never prompt with a 1970 idle start.
    if (idleSince === 0) {
        return { idleSince: now, idleMs: 0, promptDue: false, pauseAt: now + pauseAfterMs };
    }
    const idleMs = Math.max(0, now - idleSince);
    // While the screen is locked no prompt is shown; it is shown on unlock.
    const promptDue = lockedAt === null && idleMs >= promptAfterMs;
    // When the server would auto-pause (informational only — the server decides).
    const pauseAt = idleSince + pauseAfterMs;

    return { idleSince, idleMs, promptDue, pauseAt };
}

/**
 * The new `lastExemptAt`: `now` when this minute was media or call, otherwise
 * the previous value unchanged.
 */
export function exemptNow(prev, { state, now }) {
    return state === 'media' || state === 'call' ? now : prev;
}
