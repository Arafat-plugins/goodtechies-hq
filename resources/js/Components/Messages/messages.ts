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

/**
 * Which of the two chat treatments a conversation gets.
 *
 * - `sided` — a DM. Two people, so the SIDE says who spoke and the name is redundant; the
 *   viewer's own messages sit right in a solid brand bubble and the other person's sit left.
 * - `stacked` — every channel: team, announcements, a project channel, a task discussion. "Who
 *   said this" is the fact worth carrying, so every message stays left with its avatar and its
 *   author line, and the viewer's own gain a tint rather than a side.
 *
 * The 24 Sep redesign gave all five types `stacked`, which is right for four of them and wrong
 * for the fifth (POLISH-BACKLOG §B). A `null` type — the project Discussion tab mounts through
 * `emptyThread()` before its first fetch — is `stacked`, because a channel is what it turns out
 * to be and a thread must not flip layout when the payload lands.
 */
export type ThreadLayout = 'sided' | 'stacked';

export function threadLayout(type: ConversationTypeKey | null): ThreadLayout {
    return type === 'dm' ? 'sided' : 'stacked';
}

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

/* -------------------------------------------------------------------- people, drawn */

/**
 * Somebody's initials, for an avatar.
 *
 * There is **no avatar field on `users`** and this slice did not invent one, so a face here is
 * two letters of a name on a neutral medallion. Spelled locally rather than imported from
 * `Tasks/taskDetail` so the messaging client does not depend on the task client.
 */
export function initialsOf(name: string | null | undefined): string {
    return (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('') || '?';
}

/* -------------------------------------------------------------------- time, drawn */

const CLOCK = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' });
const DAY = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
const DAY_WITH_YEAR = new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

function parseTimestamp(value: string | null | undefined): Date | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

/** Just the clock, for a message inside a run that already prints its day. */
export function formatClockTime(value: string | null | undefined): string {
    const date = parseTimestamp(value);

    return date === null ? '—' : CLOCK.format(date);
}

/** The day rule between two runs of messages: Today, Yesterday, or the date. */
export function formatDayLabel(value: string | null | undefined): string {
    const date = parseTimestamp(value);

    if (date === null) {
        return 'Undated';
    }

    const startOfToday = new Date();

    startOfToday.setHours(0, 0, 0, 0);

    const days = Math.floor((startOfToday.getTime() - startOfDay(date).getTime()) / 86_400_000);

    if (days === 0) {
        return 'Today';
    }

    if (days === 1) {
        return 'Yesterday';
    }

    return date.getFullYear() === startOfToday.getFullYear() ? DAY.format(date) : DAY_WITH_YEAR.format(date);
}

function startOfDay(date: Date): Date {
    const copy = new Date(date.getTime());

    copy.setHours(0, 0, 0, 0);

    return copy;
}

/* -------------------------------------------------------------------- grouping */

/**
 * How long a run of messages by one person stays one run. Five minutes, which is the window
 * every chat client in the building already trains people to expect.
 */
export const MESSAGE_GROUP_WINDOW_MS = 5 * 60 * 1000;

/**
 * One message, with what the thread needs to know about its NEIGHBOURS to draw it.
 *
 * Computed once for the whole list rather than asked per bubble, because "is this the first of
 * a run" is a question about the message before it and a template that asks it per row ends up
 * indexing backwards into the array from inside a `v-for`.
 */
export interface RenderedMessage {
    message: ThreadMessage;
    /** First of a run by one author: it carries the avatar and the name. */
    startsRun: boolean;
    /** Set on the first message of a day, which is where the day rule is drawn. */
    dayLabel: string | null;
    /** Draw the "New messages" rule immediately above this one. */
    unreadLine: boolean;
}

/**
 * Group consecutive messages by the same author inside the window.
 *
 * A run is broken by a change of author, a gap wider than the window, a change of day, and by
 * the unread line — a run that straddled "everything below here is new" would hide the one
 * boundary on the screen that a reader is actually looking for.
 */
export function renderThread(
    messages: ThreadMessage[],
    firstUnreadId: number | null,
): RenderedMessage[] {
    let previous: ThreadMessage | null = null;
    let previousDay: string | null = null;

    return messages.map((message) => {
        const unreadLine = firstUnreadId !== null && message.id === firstUnreadId;
        const at = parseTimestamp(message.created_at);
        const day = at === null ? 'Undated' : startOfDay(at).toISOString();
        const dayChanged = day !== previousDay;

        const previousAt = parseTimestamp(previous?.created_at);
        const sameAuthor =
            previous !== null &&
            previous.is_mine === message.is_mine &&
            (previous.author?.id ?? null) === (message.author?.id ?? null);
        const withinWindow =
            at !== null &&
            previousAt !== null &&
            at.getTime() - previousAt.getTime() <= MESSAGE_GROUP_WINDOW_MS;

        const startsRun = !sameAuthor || !withinWindow || dayChanged || unreadLine;

        const rendered: RenderedMessage = {
            message,
            startsRun,
            dayLabel: dayChanged ? formatDayLabel(message.created_at) : null,
            unreadLine,
        };

        previous = message;
        previousDay = day;

        return rendered;
    });
}

/* -------------------------------------------------------------------- search */

/** `GET /messages/search?q=…` — the rail's search, answered across every conversation. */
export const MESSAGE_SEARCH_URL = '/messages/search';

/** Shorter than this is not a search: the server is not asked and the rail keeps its list. */
export const MESSAGE_SEARCH_MIN = 2;

/** Long enough that a typed word is one request, short enough to feel immediate. */
export const MESSAGE_SEARCH_DEBOUNCE_MS = 250;

export interface MessageSearchResult {
    message_id: number;
    conversation_id: number;
    conversation_label: string;
    conversation_type: ConversationTypeKey | null;
    author: string | null;
    excerpt: string;
    created_at: string | null;
}

export interface MessageSearchResponse {
    query: string;
    results: MessageSearchResult[];
    has_more: boolean;
}

/**
 * Ask the server. The caller owns the `AbortController`, because the term changing is what
 * cancels the request and only the caller knows that it has.
 *
 * Throws on anything that is not a 2xx, so the rail can draw a real error rather than an empty
 * list — "no results" and "the request failed" are different sentences and a screen that prints
 * the first when it means the second is lying.
 */
export async function searchMessages(
    query: string,
    signal: AbortSignal,
): Promise<MessageSearchResponse> {
    const response = await fetch(`${MESSAGE_SEARCH_URL}?q=${encodeURIComponent(query)}`, {
        credentials: 'same-origin',
        signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        throw new Error(`Search failed with ${response.status}`);
    }

    return (await response.json()) as MessageSearchResponse;
}

/* -------------------------------------------------------------------- the context panel */

export function conversationContextUrl(conversationId: number): string {
    return `/messages/${conversationId}/context`;
}

export interface ConversationContextFile extends FileSummary {
    kind: 'file' | 'image' | 'voice' | null;
}

export interface ConversationContextProject {
    id: number;
    name: string;
    status: string;
    href: string;
}

export interface ConversationContextTask {
    id: number;
    title: string;
    status: string;
    href: string;
}

/**
 * What the panel beside a conversation knows about it.
 *
 * `project` and `tasks` are **absent** when there is nothing this requester may see — absent,
 * not null, which is the repo's privacy shape (a field you may not see is not in the payload).
 * So every reader of this branches on `'project' in context`, never on `context.project !== null`.
 */
export interface ConversationContext {
    conversation_id: number;
    type: ConversationTypeKey | null;
    members: MessagePerson[];
    files: ConversationContextFile[];
    project?: ConversationContextProject;
    tasks?: ConversationContextTask[];
}

export type ConversationContextStatus = 'idle' | 'loading' | 'ready' | 'error';

export interface ConversationContextState {
    status: ConversationContextStatus;
    data: ConversationContext | null;
}

/**
 * One cache for the life of the page, keyed by VIEWER and conversation.
 *
 * Module scope rather than component state because the rail's rows are `Link`s: opening another
 * conversation re-renders the page, and a cache that lived in the page would be a cache that
 * emptied every time somebody clicked. The viewer's id is in the key because signing out is an
 * Inertia visit and not a reload — without it, the next person in this tab would inherit the
 * last one's panels.
 */
const contextCache = new Map<string, Ref<ConversationContextState>>();

function contextKey(viewerId: number | null, conversationId: number): string {
    return `${viewerId ?? 'anonymous'}:${conversationId}`;
}

export function conversationContextState(
    viewerId: number | null,
    conversationId: number,
): Ref<ConversationContextState> {
    const key = contextKey(viewerId, conversationId);
    const held = contextCache.get(key);

    if (held !== undefined) {
        return held;
    }

    const fresh = ref<ConversationContextState>({ status: 'idle', data: null });

    contextCache.set(key, fresh);

    return fresh;
}

/**
 * Fetch it, once.
 *
 * Nothing calls this on mount: the panel asks the first time it is OPENED, and a second open of
 * the same conversation is answered from the ref above without a request. `force` is the Retry
 * button, which is the only thing that asks twice.
 */
export async function loadConversationContext(
    viewerId: number | null,
    conversationId: number,
    force = false,
): Promise<void> {
    const state = conversationContextState(viewerId, conversationId);

    if (state.value.status === 'loading') {
        return;
    }

    if (state.value.status === 'ready' && !force) {
        return;
    }

    state.value = { status: 'loading', data: state.value.data };

    try {
        const response = await fetch(conversationContextUrl(conversationId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            state.value = { status: 'error', data: null };

            return;
        }

        state.value = { status: 'ready', data: (await response.json()) as ConversationContext };
    } catch {
        state.value = { status: 'error', data: null };
    }
}
