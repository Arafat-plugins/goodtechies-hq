import type { Ref } from 'vue';
import { computed, onScopeDispose, ref, watch } from 'vue';
import { conversationChannel } from '@/Components/Realtime/live';
import { linkWhisper, realtimeReconnects, type WhisperLink } from '@/echo';

/**
 * Polish 023: "is typing…" in a conversation, Telegram style.
 *
 * Carried as Reverb whispers on the conversation's private channel (`linkWhisper`), so a
 * keystroke costs one small websocket frame and no server work at all, and the other side sees
 * it within the socket's round trip. The sender says `typing` at most every TYPING_EVERY_MS while
 * the composer has text, and `stopped` when it is sent or emptied; the receiver forgets a typer
 * after TYPING_TTL_MS without a fresh frame, so a closed laptop never leaves "typing…" on screen.
 */

export const TYPING_EVENT = 'typing';
const TYPING_EVERY_MS = 2500;
const TYPING_TTL_MS = 6000;

interface TypingFrame {
    id?: unknown;
    name?: unknown;
    typing?: unknown;
}

export interface Typer {
    id: number;
    name: string;
}

export function useTyping(
    conversationId: () => number | null | undefined,
    me: () => { id: number; name: string } | null,
): {
    typers: Readonly<Ref<Typer[]>>;
    label: Readonly<Ref<string>>;
    noteTyping: (hasText: boolean) => void;
    stopTyping: () => void;
    forget: (userId: number | null | undefined) => void;
} {
    const seen = ref(new Map<number, { name: string; until: number }>());
    let link: WhisperLink | null = null;
    let lastSent = 0;
    let saidTyping = false;
    let ticker: ReturnType<typeof setInterval> | null = null;

    const prune = (): void => {
        const now = Date.now();
        let changed = false;
        const next = new Map(seen.value);

        for (const [id, entry] of next) {
            if (entry.until <= now) {
                next.delete(id);
                changed = true;
            }
        }

        if (changed) {
            seen.value = next;
        }

        if (next.size === 0 && ticker !== null) {
            clearInterval(ticker);
            ticker = null;
        }
    };

    const onFrame = (raw: unknown): void => {
        const frame = (raw ?? {}) as TypingFrame;
        const id = Number(frame.id);
        const mine = me();

        if (!Number.isFinite(id) || id <= 0 || (mine !== null && id === mine.id)) {
            return;
        }

        const next = new Map(seen.value);

        if (frame.typing === true) {
            next.set(id, { name: typeof frame.name === 'string' && frame.name !== '' ? frame.name : 'Someone', until: Date.now() + TYPING_TTL_MS });
            ticker ??= setInterval(prune, 1000);
        } else {
            next.delete(id);
        }

        seen.value = next;
    };

    const close = (): void => {
        if (saidTyping) {
            stopTyping();
        }

        link?.stop();
        link = null;
        seen.value = new Map();
        lastSent = 0;
    };

    watch(
        conversationId,
        (id) => {
            close();

            const channel = conversationChannel(id ?? null);

            if (channel !== null) {
                link = linkWhisper(channel, TYPING_EVENT, onFrame);
            }
        },
        { immediate: true },
    );

    // A dropped and re-made socket replaced the channel object: listen on the new one.
    watch(realtimeReconnects, () => link?.rearm());

    const visible = (): void => {
        if (document.visibilityState === 'visible') {
            link?.rearm();
        }
    };

    document.addEventListener('visibilitychange', visible);

    function send(typing: boolean): void {
        const mine = me();

        if (link === null || mine === null) {
            return;
        }

        link.send(TYPING_EVENT, { id: mine.id, name: mine.name, typing });
    }

    /** Call on every composer input. */
    function noteTyping(hasText: boolean): void {
        if (!hasText) {
            if (saidTyping) {
                stopTyping();
            }

            return;
        }

        const now = Date.now();

        if (now - lastSent >= TYPING_EVERY_MS) {
            lastSent = now;
            saidTyping = true;
            send(true);
        }
    }

    /** Sent, emptied or left: say so at once rather than waiting for the other side's timeout. */
    function stopTyping(): void {
        saidTyping = false;
        lastSent = 0;
        send(false);
    }

    /** A message from this person just arrived: they have stopped typing it. */
    function forget(userId: number | null | undefined): void {
        if (userId == null || !seen.value.has(userId)) {
            return;
        }

        const next = new Map(seen.value);

        next.delete(userId);
        seen.value = next;
    }

    onScopeDispose(() => {
        close();
        document.removeEventListener('visibilitychange', visible);

        if (ticker !== null) {
            clearInterval(ticker);
        }
    });

    const typers = computed<Typer[]>(() => [...seen.value].map(([id, entry]) => ({ id, name: entry.name })));

    const label = computed(() => {
        const list = typers.value;
        const first = (name: string): string => name.split(/\s+/)[0] || name;

        if (list.length === 0) {
            return '';
        }

        if (list.length === 1) {
            return `${first(list[0].name)} is typing…`;
        }

        if (list.length === 2) {
            return `${first(list[0].name)} and ${first(list[1].name)} are typing…`;
        }

        return `${list.length} people are typing…`;
    });

    return { typers, label, noteTyping, stopTyping, forget };
}
