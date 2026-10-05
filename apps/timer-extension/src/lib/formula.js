// formula.js — "the one formula" (docs/extension-api.md §4).
//
// No client counts on its own. `elapsed_seconds` is the server's value at
// `server_time`; we only add the time that has passed since, measured on the
// server's clock (our clock + offset). Every response resets the offset.

/** Server clock minus our clock, in ms, measured when a response arrived. */
export function clockOffset(serverTimeIso, nowMs) {
    return Date.parse(serverTimeIso) - nowMs;
}

/** Seconds on the running entry right now (0 when nothing runs; frozen when paused). */
export function elapsedSeconds(state, nowMs, offsetMs) {
    const running = state?.running ?? null;
    if (!running) {
        return 0;
    }
    const base = Number(running.elapsed_seconds) || 0;
    if (running.state !== 'running') {
        return base;
    }
    const serverNow = nowMs + offsetMs;
    return base + (serverNow - Date.parse(state.server_time)) / 1000;
}

/** "Left today": never negative; null when there is no daily target. */
export function leftTodaySeconds(state, nowMs, offsetMs) {
    const today = state?.today ?? null;
    if (!today || today.target_seconds === null || today.target_seconds === undefined) {
        return null;
    }
    const counted = Number(today.counted_seconds) || 0;
    const elapsed = elapsedSeconds(state, nowMs, offsetMs);
    return Math.max(0, today.target_seconds - (counted + elapsed));
}

/** Whole, non-negative seconds. */
function wholeSeconds(seconds) {
    const n = Math.floor(Number(seconds) || 0);
    return n > 0 ? n : 0;
}

/** "H:MM:SS", e.g. 3725 → "1:02:05". */
export function formatHms(seconds) {
    const total = wholeSeconds(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

/** "4h 18m", "18m" or "0m" (minutes rounded down). */
export function formatDuration(seconds) {
    const total = wholeSeconds(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}
