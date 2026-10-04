import { ref } from 'vue';
import type { ThreadPayload } from '@/Components/Messages/messages';

/**
 * Which chat the reader just tapped, while its thread is on its way (2026-10-04).
 *
 * Opening a chat from the list is a PARTIAL Inertia visit — `active`, `conversations` and
 * `announcement` only — so the page, the list and the roster stay put and only the thread is
 * fetched. Between the tap and the answer the Messages page draws a preview of that chat
 * (`ThreadSkeleton`), so the tap is answered at once, the way a chat app answers it.
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

/* ------------------------------------------------------------------ the preview's real shape */

/**
 * One message as the opening preview draws it: who, which side, the words, and what KIND of file
 * it carries — never a link. The preview never shimmers where there is nothing (2026-10-04, the
 * client: the loading "shows everywhere, where there is no message"); it draws the chat's real
 * messages from the last time it was on screen, and shimmers only where a picture, a voice note
 * or a file will load.
 */
export interface PreviewMessage {
    id: number;
    mine: boolean;
    author: string | null;
    body: string | null;
    files: Array<'image' | 'voice' | 'file'>;
    at: string | null;
    deleted: boolean;
}

/** How many of a chat's newest messages are remembered, and for how many chats. */
const KEEP_MESSAGES = 20;
const KEEP_CHATS = 40;

/** This tab's memory of the chats it has shown, newest use last. Never stored anywhere. */
const remembered = new Map<number, PreviewMessage[]>();

export function rememberThread(payload: Pick<ThreadPayload, 'conversation_id' | 'messages'> | null | undefined): void {
    if (payload == null || payload.conversation_id <= 0) {
        return;
    }

    const messages = payload.messages
        .filter((message) => message.id > 0 && message.pending !== true && message.failed !== true)
        .slice(-KEEP_MESSAGES)
        .map<PreviewMessage>((message) => ({
            id: message.id,
            mine: message.is_mine,
            author: message.author?.name ?? null,
            body: message.is_deleted ? null : message.body,
            files: message.is_deleted
                ? []
                : message.attachments.map((file) => (file.kind === 'image' || file.kind === 'voice' ? file.kind : 'file')),
            at: message.created_at,
            deleted: message.is_deleted,
        }));

    remembered.delete(payload.conversation_id);
    remembered.set(payload.conversation_id, messages);

    while (remembered.size > KEEP_CHATS) {
        const oldest = remembered.keys().next().value;

        if (oldest === undefined) {
            break;
        }

        remembered.delete(oldest);
    }
}

/** The chat's messages from the last time it was on screen, or `null` if it never was. */
export function rememberedThread(id: number): PreviewMessage[] | null {
    return remembered.get(id) ?? null;
}
