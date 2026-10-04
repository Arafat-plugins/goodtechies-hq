import { ref } from 'vue';

/**
 * Which chat the reader just tapped, while its thread is on its way (2026-10-04).
 *
 * Opening a chat from the list is a PARTIAL Inertia visit — `active`, `conversations` and
 * `announcement` only — so the page, the list and the roster stay put and only the thread is
 * fetched. Between the tap and the answer the Messages page draws a shimmering thread for this
 * id, so the tap is answered at once, the way a chat app answers it.
 *
 * `null` when nothing is opening. A second tap before the first lands replaces it (Inertia
 * cancels the first visit), which is why `finishOpening` only clears its own id.
 */
export const openingId = ref<number | null>(null);

/** What a list row's `Link` asks the server for: the thread and the lists that move with it. */
export const OPEN_PROPS = ['active', 'conversations', 'announcement'];

export function startOpening(id: number): void {
    openingId.value = id;
}

export function finishOpening(id: number): void {
    if (openingId.value === id) {
        openingId.value = null;
    }
}
