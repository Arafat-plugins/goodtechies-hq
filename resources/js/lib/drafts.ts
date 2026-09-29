/**
 * Half-typed messages that survive an F5 or a click elsewhere (reliability slice 3).
 *
 * **sessionStorage, never localStorage.** A draft lives exactly as long as the tab: closing it on a
 * shared office PC must not leave the last person's unsent sentence for the next one to find. The
 * key carries the signed-in user as well as the conversation — `hq.draft.msg.<userId>.<id>` — so a
 * second person signing in in the same tab never sees the first one's text, and signing out clears
 * every `hq.draft.*` key anyway (`app.ts`).
 *
 * Text only. A picked file or a voice clip cannot be put in storage and cannot survive a reload;
 * the unsaved-changes guard (`lib/unsavedGuard.ts`) asks before either is thrown away.
 *
 * Every storage call is wrapped: private windows and full quotas throw, and a draft that could
 * not be kept is not a reason for the composer to break (DESIGN.md §5.9 — storage lives in `lib/`).
 */

const PREFIX = 'hq.draft.';

export function messageDraftKey(userId: number | null | undefined, conversationId: number | null | undefined): string | null {
    if (userId === null || userId === undefined || conversationId === null || conversationId === undefined) {
        return null;
    }

    return `${PREFIX}msg.${userId}.${conversationId}`;
}

function store(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.sessionStorage;
    } catch {
        return null;
    }
}

export function readDraft(key: string | null): string {
    if (key === null) {
        return '';
    }

    try {
        return store()?.getItem(key) ?? '';
    } catch {
        return '';
    }
}

/** An empty (or whitespace-only) draft is removed rather than stored. */
export function writeDraft(key: string | null, text: string): void {
    if (key === null) {
        return;
    }

    try {
        if (text.trim() === '') {
            store()?.removeItem(key);
        } else {
            store()?.setItem(key, text);
        }
    } catch {
        // Quota or a private window: the draft is simply not kept.
    }
}

export function clearDraft(key: string | null): void {
    writeDraft(key, '');
}

/** Sign-out: every draft this tab holds, whoever wrote it. */
export function clearAllDrafts(): void {
    removeDrafts((key) => key.startsWith(PREFIX));
}

/**
 * App boot: every message draft that is not the signed-in person's goes — all of them for a
 * guest. A sign-out that never reached `clearAllDrafts` (a session that simply expired, a tab
 * reused by somebody else) cannot then leave one person's words for the next.
 */
export function clearDraftsNotOwnedBy(userId: number | null): void {
    const own = userId === null ? null : `${PREFIX}msg.${userId}.`;

    removeDrafts((key) => key.startsWith(`${PREFIX}msg.`) && (own === null || !key.startsWith(own)));
}

function removeDrafts(matches: (key: string) => boolean): void {
    const s = store();

    if (s === null) {
        return;
    }

    try {
        const keys: string[] = [];

        for (let i = 0; i < s.length; i++) {
            const key = s.key(i);

            if (key !== null && matches(key)) {
                keys.push(key);
            }
        }

        for (const key of keys) {
            s.removeItem(key);
        }
    } catch {
        // Nothing to do: storage that cannot be read holds nothing we wrote.
    }
}
