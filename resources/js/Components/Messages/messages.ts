import { router } from '@inertiajs/vue3';
import type { Errors, FormDataConvertible } from '@inertiajs/core';
import { ref, type Ref } from 'vue';
import type { FileSummary } from '@/Components/Files/files';

/**
 * The messaging client: the one shape of a thread, the endpoints that read and write one, and
 * the mention rule the server applies, applied here too so the two cannot disagree.
 *
 * Everything in this file is shared by three mounts — the Messages page, the task detail's
 * Discussion panel and the project detail's Discussion tab. A payload described twice is a
 * payload that grows a field on one screen and not the other, which this repo has already been
 * through with the tag pickers.
 */

/** Somebody's name, as every message payload carries it. */
export interface MessagePerson {
    id: number;
    name: string | null;
}

export interface ThreadAttachment extends FileSummary {
    kind: 'file' | 'image' | 'voice' | null;
    /** Only ever set on a voice note — an ordinary audio upload carries no duration. */
    duration_seconds: number | null;
}

/**
 * One message.
 *
 * `is_mine` and `mentions_me` are both resolved by `MessageResource`, so the screen never
 * compares ids to work out whose bubble it is drawing or whether a line is addressed to it.
 * There is deliberately no `can_edit` and no `can_delete`: nobody may change or remove a
 * message, so there is no permission to report and no control to wire.
 */
export interface ThreadMessage {
    id: number;
    body: string | null;
    author: MessagePerson | null;
    is_mine: boolean;
    created_at: string | null;
    attachments: ThreadAttachment[];
    /**
     * Who this message named. **Not a permission** — `ConversationPolicy` never reads
     * `message_mentions` — so it is safe to show to everybody who can read the thread: it names
     * people they can already see named in the body.
     */
    mentions: MessagePerson[];
    mentions_me: boolean;
}

/**
 * A thread, in the one shape `BuildsDiscussionPayload` sends it.
 *
 * Identical from a detail page's props, from `GET {surface}/tasks/{id}/discussion` and from
 * `GET /messages/{conversation}`, which is what lets a panel paint from the first and refresh
 * from the others without holding two ideas of what a thread is.
 */
export interface ThreadPayload {
    conversation_id: number;
    type: ConversationTypeKey | null;
    /** What this conversation is called TO THIS READER — a DM is the other person's name. */
    label: string;
    /**
     * Whether this requester may post — `ConversationPolicy::post`. It is the ONLY thing that
     * decides whether a composer exists. Not a role, not `is_mine`, and never a
     * `conversation_members` row: membership is read state and grants nothing, so a row left
     * behind on a reassigned task or a project somebody left must buy its holder nothing.
     */
    can_post: boolean;
    /**
     * Read state, not authorisation. It positions the unread line and gates nothing at all — a
     * reader who has read everything and a reader who has read none of it may do exactly the
     * same things.
     */
    last_read_at: string | null;
    unread_count: number;
    messages: ThreadMessage[];
    /** Is there history older than the window in hand? */
    has_more: boolean;
    /**
     * The @mention picker's options: exactly the people the server will accept a mention of,
     * computed once by `ConversationService::mentionableIn()`. A name offered here is never one
     * the write then drops.
     */
    mentionable: MessagePerson[];
}

export type ConversationTypeKey = 'team' | 'project' | 'task' | 'dm' | 'announcement';

/** One row of the Messages page's left rail. */
export interface ConversationSummary {
    id: number;
    type: ConversationTypeKey | null;
    group: string | null;
    label: string;
    unread_count: number;
    last_message: {
        author: string | null;
        is_mine: boolean;
        excerpt: string;
        created_at: string | null;
    } | null;
}

/** The banner the plan asks an announcement to raise. */
export interface AnnouncementBanner {
    conversation_id: number;
    body: string;
    author: string | null;
    created_at: string | null;
    is_unread: boolean;
}

/**
 * `StoreMessageRequest::MAX_BODY`, restated so a composer can count down to it.
 *
 * A counter, not a second rule: the field is not capped at this and the submit is not blocked
 * by it. The server owns the refusal and says it in its own words, the same way the file limits
 * in `Files/files.ts` are a courtesy in front of `FileService`.
 */
export const MESSAGE_MAX_BODY = 4000;

/** The order the plan draws the rail in: Team, Announcements, Projects, Direct. */
export const CONVERSATION_GROUPS = ['Team', 'Announcements', 'Projects', 'Direct'] as const;

/**
 * Every endpoint a thread needs, for whichever screen it is mounted on.
 *
 * Two builders and one type, because the task discussion lives under its task's surface and
 * everything else lives under `/messages` — a component that took a surface and an id would
 * have had to know which of those two worlds it was in.
 */
export interface ThreadRoutes {
    /** GET: the thread, fresh. Also what marks it read. */
    thread: string;
    /** POST: a message. */
    store: string;
    /** POST: move the unread line to now, without saying anything. */
    read: string | null;
}

export function conversationRoutes(conversationId: number): ThreadRoutes {
    const base = `/messages/${conversationId}`;

    return { thread: base, store: base, read: `${base}/read` };
}

export function taskThreadRoutes(surface: 'admin' | 'employee', taskId: number): ThreadRoutes {
    const base = `/${surface}/tasks/${taskId}/discussion`;

    // The task discussion has no separate read endpoint: its GET marks it read, which is the
    // Phase 2 behaviour and the reason the panel calls it on mount.
    return { thread: base, store: base, read: null };
}

/**
 * A thread with nothing in it yet, for a mount that fetches its own payload.
 *
 * The project detail's Discussion tab works the way `FilePanel` does: the page carries the
 * conversation's id and nothing else, and the thread asks the server for the rest when it is
 * opened. A project page nobody opens the tab on therefore costs nothing, and what it then
 * shows is the payload the policy built rather than one assembled from the page's props.
 *
 * `can_post` starts false on purpose. A composer that appears and then vanishes when the real
 * answer lands is worse than one that appears a moment late, and the server is the only thing
 * that may say yes.
 */
export function emptyThread(conversationId: number, label = ''): ThreadPayload {
    return {
        conversation_id: conversationId,
        type: null,
        label,
        can_post: false,
        last_read_at: null,
        unread_count: 0,
        messages: [],
        has_more: false,
        mentionable: [],
    };
}

/** The Messages page, optionally opened on one conversation. */
export function messagesHref(conversationId?: number): string {
    return conversationId === undefined ? '/messages' : `/messages?conversation=${conversationId}`;
}

/* -------------------------------------------------------------------- mentions */

/**
 * The composer's half of the mention rule.
 *
 * `MessageService` keeps a mention only if the named person can read the conversation AND the
 * body still contains `@` followed by their name. The first half is already true of everybody
 * in `mentionable`; this is the second half, applied here so that what the composer sends is
 * what the server will keep — a picked name whose text the writer then deleted is not sent as a
 * mention, rather than sent and silently dropped.
 */
export function namedInBody(body: string, name: string | null): boolean {
    const trimmed = (name ?? '').trim();

    if (trimmed === '') {
        return false;
    }

    const first = trimmed.split(/\s+/)[0] ?? '';

    return [trimmed, first].some(
        (candidate) =>
            candidate !== '' &&
            new RegExp(`@${candidate.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`, 'iu').test(body),
    );
}

/**
 * Track which people a composer has named.
 *
 * `insert()` puts `@Name` into the body where the caret is and remembers the id; `ids()` hands
 * back only the ones still named in the text. Reset when the message is sent, or when the
 * thread under the composer changes.
 */
export function useMentions(body: Ref<string>) {
    const picked = ref<MessagePerson[]>([]);

    function insert(person: MessagePerson, caret: number | null = null): string {
        const name = (person.name ?? '').trim();

        if (name === '') {
            return body.value;
        }

        const at = caret ?? body.value.length;
        const before = body.value.slice(0, at);
        const after = body.value.slice(at);
        // A space in front unless we are at the start or already after one, and one behind, so
        // the writer carries on typing rather than clearing up punctuation.
        const lead = before === '' || /\s$/.test(before) ? '' : ' ';

        body.value = `${before}${lead}@${name} ${after}`;

        if (!picked.value.some((person_) => person_.id === person.id)) {
            picked.value = [...picked.value, person];
        }

        return body.value;
    }

    function ids(): number[] {
        return picked.value
            .filter((person) => namedInBody(body.value, person.name))
            .map((person) => person.id);
    }

    function reset(): void {
        picked.value = [];
    }

    return { picked, insert, ids, reset };
}

/* -------------------------------------------------------------------- writing */

export interface MessageMutationOptions {
    forceFormData?: boolean;
    onAccepted?: () => void;
    onInvalid?: (errors: Errors) => void;
    onFinish?: () => void;
}

/**
 * Send something to a messaging endpoint.
 *
 * `preserveState` and `preserveScroll`, because a thread you are reading must not jump when you
 * post into it — and because the left rail's scroll position is part of where you were.
 */
export function mutateMessage(
    url: string,
    data: Record<string, FormDataConvertible>,
    options: MessageMutationOptions = {},
): void {
    router.post(url, data, {
        forceFormData: options.forceFormData,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => options.onAccepted?.(),
        onError: (errors) => options.onInvalid?.(errors),
        onFinish: () => options.onFinish?.(),
    });
}

/* -------------------------------------------------------------------- reading */

const DATE_TIME = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

export function formatMessageTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE_TIME.format(date);
}

/** A voice note's length. Nothing records one yet; this renders one if it arrives. */
export function formatDuration(seconds: number | null): string {
    if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) {
        return '';
    }

    return `${Math.floor(seconds / 60)}:${String(Math.round(seconds % 60)).padStart(2, '0')}`;
}
