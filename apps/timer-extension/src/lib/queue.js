// queue.js — the offline heartbeat queue (docs/extension-api.md §7, §9).
//
// Shape: { [time_entry_id]: Sample[] }, oldest first. Pure helpers: every
// function returns a new object and never mutates its input.

/** One entry's list is capped at 24 h of minutes. */
export const MAX_PER_ENTRY = 1440;

/** Add a sample for an entry; a sample for the same `minute` is replaced in place. */
export function enqueue(queue, entryId, sample) {
    const key = String(entryId);
    const list = [...((queue ?? {})[key] ?? [])];
    const at = list.findIndex((s) => s.minute === sample.minute);
    if (at >= 0) {
        list[at] = sample;
    } else {
        list.push(sample);
    }
    // Drop the oldest beyond the cap.
    const capped = list.length > MAX_PER_ENTRY ? list.slice(list.length - MAX_PER_ENTRY) : list;
    return { ...(queue ?? {}), [key]: capped };
}

/** The oldest `max` samples of an entry as `batch`, and the queue without them as `rest`. */
export function take(queue, entryId, max = 120) {
    const key = String(entryId);
    const list = (queue ?? {})[key] ?? [];
    const batch = list.slice(0, max);
    const remaining = list.slice(max);
    const rest = { ...(queue ?? {}) };
    if (remaining.length > 0) {
        rest[key] = remaining;
    } else {
        delete rest[key];
    }
    return { batch, rest };
}

/** The queue without this entry's samples. */
export function drop(queue, entryId) {
    const rest = { ...(queue ?? {}) };
    delete rest[String(entryId)];
    return rest;
}

/** Total samples waiting, across all entries. */
export function size(queue) {
    return Object.values(queue ?? {}).reduce((acc, list) => acc + (Array.isArray(list) ? list.length : 0), 0);
}
