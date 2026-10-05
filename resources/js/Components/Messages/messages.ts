import { ref, type Ref } from 'vue';
import type { FileSummary } from '@/Components/Files/files';
import type { StatusKey } from '@/Components/StatusBadge.vue';

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
 * `can_edit` / `can_delete` / `reactions` / `seen` arrive from `MessageResource` (brief 006);
 * the controls that use them come in a later brief. `pending` / `failed` are local-only: they
 * mark an optimistic message this composer has sent and the server has not answered yet.
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
    edited_at: string | null;
    is_deleted: boolean;
    can_edit: boolean;
    can_delete: boolean;
    reactions: MessageReaction[];
    /** A DM's tick: did the other person read it? `null` where it does not apply. */
    seen: boolean | null;
    /** Brief 013: the message this one answers, as `MessageResource::replyTo()` quotes it. */
    reply_to: ThreadReplyTo | null;
    /** Local only: an optimistic send still waiting for its answer. */
    pending?: boolean;
    /** Local only: an optimistic send that got no answer — tap to retry. */
    failed?: boolean;
}

/**
 * The quoted original of a reply: who wrote it, what kind it is, and a short excerpt (the first
 * 120 characters of its text, or "Voice message" / "Photo" / the file name; "" once deleted).
 */
export interface ThreadReplyTo {
    id: number;
    author: { id: number; name: string } | null;
    excerpt: string;
    kind: 'text' | 'voice' | 'image' | 'file';
    is_deleted: boolean;
}

/**
 * The same quote, built here from a message already on screen — what an optimistic reply carries
 * until the server's own `reply_to` replaces it. Mirrors `MessageResource::replyTo()`.
 */
export function replyReference(message: ThreadMessage, viewerName: string | null = null): ThreadReplyTo {
    const deleted = message.is_deleted === true;
    const attachment = deleted ? undefined : message.attachments[0];
    const kind: ThreadReplyTo['kind'] =
        attachment === undefined
            ? 'text'
            : attachment.kind === 'voice'
              ? 'voice'
              : attachment.kind === 'image'
                ? 'image'
                : 'file';
    const body = (message.body ?? '').trim();
    let excerpt = '';

    if (!deleted) {
        if (body !== '') {
            excerpt = Array.from(body).slice(0, 120).join('');
        } else if (kind === 'voice') {
            excerpt = 'Voice message';
        } else if (kind === 'image') {
            excerpt = 'Photo';
        } else if (kind === 'file') {
            excerpt = attachment?.name ?? '';
        }
    }

    const name = message.author?.name ?? (message.is_mine ? viewerName : null);

    return {
        id: message.id,
        author: message.author === null ? null : { id: message.author.id, name: name ?? '' },
        excerpt,
        kind,
        is_deleted: deleted,
    };
}

/** What a quote says on one line: the excerpt, or the kind in words when there is no text. */
export function replyExcerpt(reply: ThreadReplyTo): string {
    if (reply.is_deleted) {
        return 'Deleted message';
    }

    if (reply.excerpt !== '') {
        return reply.excerpt;
    }

    return reply.kind === 'voice' ? 'Voice message' : reply.kind === 'image' ? 'Photo' : 'Message';
}

export interface MessageReaction {
    emoji: string;
    count: number;
    mine: boolean;
    names: string[];
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
    /** A DM's other person; `null` on every channel. */
    peer: { id: number; name: string; last_seen_at: string | null } | null;
    /** A group's name, picture, members and whether this reader may manage it (12-81). */
    group?: ThreadGroup | null;
}

/** `GroupController::present()` — a group as its thread and its manage endpoints send it. */
export interface ThreadGroup {
    id: number;
    name: string;
    avatar_url: string | null;
    members: { id: number; name: string }[];
    can_manage: boolean;
}

export type ConversationTypeKey = 'team' | 'project' | 'task' | 'dm' | 'announcement' | 'group';

/**
 * Which of the two chat treatments a conversation gets.
 *
 * - `sided` — Telegram-style: the viewer's own messages sit right in a solid brand bubble and
 *   everybody else's sit left, two sides of bubbles.
 * - `stacked` — every message left with its avatar and its author line, the viewer's own with a
 *   tint rather than a side.
 *
 * Every conversation on the Messages page is drawn Telegram-style (`sided`): DMs, groups, team,
 * announcements and project channels alike. Only a task's discussion (inside the task drawer)
 * keeps the stacked layout (2026-10-04, client asked for the Telegram reference). A `null`
 * type — the project Discussion tab before its first fetch — is `sided` too.
 */
export type ThreadLayout = 'sided' | 'stacked';

export function threadLayout(type: ConversationTypeKey | null): ThreadLayout {
    return type === 'task' ? 'stacked' : 'sided';
}

/** One row of the Messages page's left rail. */
export interface ConversationSummary {
    id: number;
    type: ConversationTypeKey | null;
    group: string | null;
    label: string;
    unread_count: number;
    /** A group's picture (12-81); `null` everywhere else, and for a group without one. */
    avatar_url?: string | null;
    /** A group's head count; `null` for every other type. */
    member_count?: number | null;
    /** A DM's other person, for the online dot and "last seen". `null` on channels. */
    peer_id?: number | null;
    peer_last_seen_at?: string | null;
    last_message: {
        author: string | null;
        is_mine: boolean;
        excerpt: string;
        created_at: string | null;
    } | null;
}

/**
 * One row of the Telegram-style chat list (2026-10-04): a conversation, or the single
 * "Projects" folder that holds every project channel which has at least one message.
 */
export type ChatListEntry =
    | { kind: 'chat'; row: ConversationSummary }
    | { kind: 'projects'; rows: ConversationSummary[]; unread: number; latestAt: string | null };

function activityOf(row: ConversationSummary): number {
    const at = row.last_message?.created_at ? Date.parse(row.last_message.created_at) : Number.NaN;

    return Number.isNaN(at) ? Number.NEGATIVE_INFINITY : at;
}

/**
 * The chat list, newest activity first. Project channels leave the main list and go into one
 * folder entry — only those with a message; a project nobody has written in is not shown at
 * all. The folder sits where its newest project message puts it, and carries the projects'
 * unread total. Rows that have never had a message keep their server order, after the rest.
 */
export function chatListEntries(conversations: ConversationSummary[]): ChatListEntry[] {
    const projects = conversations
        .filter((row) => row.type === 'project' && row.last_message !== null)
        .map((row, index) => ({ row, index }))
        .sort((a, b) => activityOf(b.row) - activityOf(a.row) || a.index - b.index)
        .map(({ row }) => row);

    const entries: { entry: ChatListEntry; at: number; index: number }[] = conversations
        .filter((row) => row.type !== 'project' && row.type !== 'task')
        .map((row, index) => ({ entry: { kind: 'chat', row } as ChatListEntry, at: activityOf(row), index }));

    if (projects.length > 0) {
        entries.push({
            entry: {
                kind: 'projects',
                rows: projects,
                unread: projects.reduce((sum, row) => sum + row.unread_count, 0),
                latestAt: projects[0].last_message?.created_at ?? null,
            },
            at: activityOf(projects[0]),
            index: entries.length,
        });
    }

    return entries.sort((a, b) => b.at - a.at || a.index - b.index).map(({ entry }) => entry);
}

/**
 * The time on a chat-list row, as Telegram writes it: the clock for today, the weekday within
 * the last 6 days, else the day and month ("1 Oct"), with the year when it is not this year.
 */
export function formatListTime(value: string | null | undefined, now: Date = new Date()): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const dayMs = 24 * 60 * 60 * 1000;

    if (date.getTime() >= startOfToday) {
        return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(date);
    }

    if (date.getTime() >= startOfToday - 6 * dayMs) {
        return new Intl.DateTimeFormat('en-GB', { weekday: 'short' }).format(date);
    }

    return new Intl.DateTimeFormat('en-GB', date.getFullYear() === now.getFullYear()
        ? { day: 'numeric', month: 'short' }
        : { day: 'numeric', month: 'short', year: 'numeric' }).format(date);
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
 * `StoreMessageRequest::MAX_BODY` (20 000 since brief 012), restated for the edit field's
 * `maxlength`. The composer neither counts down to it nor caps at it: the server owns the
 * refusal and says it in its own words.
 */
export const MESSAGE_MAX_BODY = 20000;

/* -------------------------------------------------------------------- attachments */

/**
 * What a MESSAGE attachment may be: `FileService::TYPES` plus `MESSAGE_EXTRA_TYPES` (12-82 —
 * svg, mp3, mp4, apk; zip was already allowed). The task / project / client Files panels keep
 * the shorter list in `Files/files.ts`. There is no size limit on a message attachment in the
 * application, so none is checked here.
 */
export const MESSAGE_FILE_EXTENSIONS = [
    'png',
    'jpg',
    'jpeg',
    'gif',
    'webp',
    'pdf',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'ppt',
    'pptx',
    'csv',
    'txt',
    'md',
    'zip',
    'svg',
    'mp3',
    'mp4',
    'apk',
] as const;

/** The composer's `accept` attribute. */
export const MESSAGE_FILE_ACCEPT = MESSAGE_FILE_EXTENSIONS.map((extension) => `.${extension}`).join(',');

/** Why the server would refuse this file's TYPE, in its words — or null to send it. */
export function messageFileRejection(file: { name: string }): string | null {
    const extension = file.name.includes('.')
        ? file.name.slice(file.name.lastIndexOf('.') + 1).toLowerCase()
        : '';

    if (!(MESSAGE_FILE_EXTENSIONS as readonly string[]).includes(extension)) {
        return `${extension === '' ? 'Extensionless' : extension.toUpperCase()} files are not accepted. `
            + `Allowed: ${MESSAGE_FILE_EXTENSIONS.join(', ')}.`;
    }

    return null;
}

function pad2(value: number): string {
    return String(value).padStart(2, '0');
}

/**
 * A pasted image's name: `pasted-<yyyyMMdd-HHmmss>.<ext>` from its MIME type (`png` when the
 * type says nothing usable). A clipboard image arrives as `image.png` or nameless.
 */
export function pastedFileName(type: string, at: Date): string {
    const stamp = `${at.getFullYear()}${pad2(at.getMonth() + 1)}${pad2(at.getDate())}-${pad2(at.getHours())}${pad2(at.getMinutes())}${pad2(at.getSeconds())}`;
    const subtype = type.startsWith('image/') ? (type.slice(6).split(/[+;]/)[0] ?? '') : '';
    const ext = subtype.replace('jpeg', 'jpg').replace(/[^a-z0-9]/gi, '').toLowerCase() || 'png';

    return `pasted-${stamp}.${ext}`;
}

/** The parts of `DataTransfer` the paste reader looks at, so it can be tested without a DOM. */
export interface ClipboardLike {
    items?: ArrayLike<{ kind: string; type: string; getAsFile(): File | null }> | null;
    files?: ArrayLike<File> | null;
}

/**
 * The image on a clipboard: the first `items` entry of kind `file` with an `image/*` type, else
 * `files[0]` (the composer's type check then speaks for it). `null` for a text-only clipboard.
 */
export function clipboardImage(data: ClipboardLike | null | undefined): File | null {
    if (data === null || data === undefined) {
        return null;
    }

    for (const item of Array.from(data.items ?? [])) {
        if (item.kind === 'file' && item.type.startsWith('image/')) {
            const file = item.getAsFile();

            if (file !== null) {
                return file;
            }
        }
    }

    return data.files?.[0] ?? null;
}

/** The order the plan draws the rail in: Team, Announcements, Projects, Groups, Direct. */
export const CONVERSATION_GROUPS = ['Team', 'Announcements', 'Projects', 'Groups', 'Direct'] as const;

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
        peer: null,
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

/** What `sendMessage()` resolves with: the status, and the JSON body when there was one. */
export interface MessageSendResult {
    status: number;
    json: unknown;
}

/**
 * Post a message as JSON (brief 009) — no Inertia visit, so no "Message sent." flash and no
 * full-page props reload. `XMLHttpRequest` rather than `fetch` because an attachment reports
 * upload progress and `fetch` cannot. Resolves with ANY status (the caller reads 201 / 422 / …);
 * rejects only when no answer came back (offline, a dropped connection, a timeout).
 */
export function sendMessage(
    url: string,
    data: FormData,
    csrf: string,
    onProgress?: UploadProgress,
    timeoutMs = 0,
    stallMs = 0,
): Promise<MessageSendResult> {
    return messageRequest('POST', url, data, csrf, onProgress, timeoutMs, stallMs);
}

/** Percent sent, plus the bytes behind it (polish 017: "1.2 of 3.7 MB"). */
export type UploadProgress = (percent: number, loaded: number, total: number) => void;

/**
 * Polish 017: an upload that stops moving. Rejected with this BEFORE the body has fully left the
 * device, so the server cannot have stored anything and sending it again can never duplicate it.
 */
export const UPLOAD_STALLED = 'upload-stalled';

/**
 * The same request `sendMessage()` makes, for any verb (brief 010: edit, delete, react, groups).
 * A plain object is sent as JSON; a `FormData` as multipart; `null` as no body. Same headers,
 * same resolve-on-any-status / reject-on-no-answer contract.
 */
export function messageRequest(
    method: 'POST' | 'PATCH' | 'DELETE',
    url: string,
    data: FormData | Record<string, unknown> | null,
    csrf: string = xsrfToken(),
    onProgress?: UploadProgress,
    timeoutMs = 0,
    stallMs = 0,
): Promise<MessageSendResult> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();

        // Polish 017: a body that has not moved for `stallMs` is aborted and rejected as
        // UPLOAD_STALLED, so a dead connection never leaves "Uploading… 15%" on screen forever.
        let uploaded = false;
        let sentAll = false;
        let stalled = false;
        let watchdog: ReturnType<typeof setTimeout> | null = null;
        const stopWatchdog = (): void => {
            if (watchdog !== null) {
                clearTimeout(watchdog);
                watchdog = null;
            }
        };
        const armWatchdog = (): void => {
            stopWatchdog();

            if (stallMs > 0 && !uploaded) {
                watchdog = setTimeout(() => {
                    stalled = true;
                    xhr.abort();
                }, stallMs);
            }
        };

        xhr.open(method, url);
        xhr.withCredentials = true;
        xhr.timeout = timeoutMs;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-XSRF-TOKEN', csrf);

        const isForm = typeof FormData !== 'undefined' && data instanceof FormData;

        if (data !== null && !isForm) {
            xhr.setRequestHeader('Content-Type', 'application/json');
        }

        if (onProgress !== undefined || stallMs > 0) {
            xhr.upload.onprogress = (event) => {
                armWatchdog();
                sentAll = event.lengthComputable && event.total > 0 && event.loaded >= event.total;

                if (onProgress !== undefined && event.lengthComputable && event.total > 0) {
                    onProgress(Math.round((event.loaded / event.total) * 100), event.loaded, event.total);
                }
            };
            // Chrome fires `upload.load` even when the connection dies mid-body (seen with the
            // network going offline at 16%), so "all of it went" is taken from the last progress
            // event, not from this one.
            xhr.upload.onload = () => {
                uploaded = sentAll;
                stopWatchdog();
            };
        }

        xhr.onloadend = stopWatchdog;
        xhr.onload = () => {
            let json: unknown = null;

            try {
                json = xhr.responseText === '' ? null : JSON.parse(xhr.responseText);
            } catch {
                json = null;
            }

            resolve({ status: xhr.status, json });
        };
        // A connection that drops while the body is still going up is as safe to resend as a stall.
        xhr.onerror = () => reject(new Error(stallMs > 0 && !uploaded ? UPLOAD_STALLED : 'network'));
        xhr.ontimeout = () => reject(new Error('timeout'));
        xhr.onabort = () => reject(new Error(stalled || (stallMs > 0 && !uploaded) ? UPLOAD_STALLED : 'abort'));

        xhr.send(data === null ? null : isForm ? (data as FormData) : JSON.stringify(data));
        armWatchdog();
    });
}

/** The `XSRF-TOKEN` cookie Laravel sets, decoded — what `X-XSRF-TOKEN` carries. */
export function xsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** `PATCH` / `DELETE` one message; `+ '/reactions'` to toggle a reaction (brief 010). */
export function messageUrl(conversationId: number, messageId: number): string {
    return `/messages/${conversationId}/messages/${messageId}`;
}

/** The `{ message }` every edit / delete / reaction answers with, or `null` for anything else. */
export function messageFrom(json: unknown): ThreadMessage | null {
    const message = (json as { message?: unknown } | null)?.message;

    return message !== null && typeof message === 'object' ? (message as ThreadMessage) : null;
}

/**
 * Toggle `emoji` for the viewer, locally — the optimistic half of a reaction (brief 010). The
 * server's `message` replaces this as soon as it answers.
 */
export function toggleReaction(message: ThreadMessage, emoji: string, viewerName = 'You'): ThreadMessage {
    const reactions = message.reactions ?? [];
    const held = reactions.find((reaction) => reaction.emoji === emoji);
    let next: MessageReaction[];

    if (held === undefined) {
        next = [...reactions, { emoji, count: 1, mine: true, names: [viewerName] }];
    } else if (held.mine) {
        next = reactions
            .map((reaction) =>
                reaction.emoji === emoji
                    ? {
                          ...reaction,
                          count: reaction.count - 1,
                          mine: false,
                          names: reaction.names.filter((name) => name !== viewerName),
                      }
                    : reaction,
            )
            .filter((reaction) => reaction.count > 0);
    } else {
        next = reactions.map((reaction) =>
            reaction.emoji === emoji
                ? { ...reaction, count: reaction.count + 1, mine: true, names: [...reaction.names, viewerName] }
                : reaction,
        );
    }

    return { ...message, reactions: next };
}

/** The first sentence of a 422, the way the composer has always shown it. */
export function refusalText(json: unknown): string {
    const answer = (json ?? {}) as { message?: unknown; errors?: Record<string, unknown> };
    const errors = answer.errors ?? {};

    for (const key of ['body', 'file', 'mentions']) {
        const value = errors[key];
        const text = Array.isArray(value) ? value[0] : value;

        if (typeof text === 'string' && text !== '') {
            return text;
        }
    }

    return typeof answer.message === 'string' && answer.message !== '' ? answer.message : 'That message was refused.';
}

/* -------------------------------------------------------------------- merging a re-read */

/** Same attachment for drawing purposes: same file, and its link not about to lapse. */
function attachmentKeep(held: ThreadAttachment, refreshLinksBefore: number): boolean {
    const expires = Date.parse(held.url_expires_at);

    return Number.isNaN(expires) || expires > refreshLinksBefore;
}

function sameMessage(held: ThreadMessage, fresh: ThreadMessage): boolean {
    return (
        held.body === fresh.body &&
        (held.edited_at ?? null) === (fresh.edited_at ?? null) &&
        (held.is_deleted ?? false) === (fresh.is_deleted ?? false) &&
        (held.seen ?? null) === (fresh.seen ?? null) &&
        JSON.stringify(held.reactions ?? []) === JSON.stringify(fresh.reactions ?? []) &&
        held.attachments.length === fresh.attachments.length &&
        held.attachments.every((file, index) => file.id === fresh.attachments[index]?.id)
    );
}

/**
 * Brief 009, no flashing: fold a re-read into what is on screen.
 *
 * Every incoming message that is already drawn and has not changed (body, edit, deletion, seen,
 * reactions, the same attachment ids) comes back as the SAME object, so Vue re-renders nothing
 * for it. A changed message is the fresh one, but an attachment it still carries keeps the
 * object — and so the signed `url` — it was first drawn with, so an image or a voice note does
 * not reload because the server minted a new signature. The one exception is a link that
 * lapses before `refreshLinksBefore` (epoch ms): that one takes the fresh url, which is what
 * the thread re-reads for. The result is in `incoming`'s order; when nothing at all changed it
 * is `current` itself.
 */
export function mergeThreadMessages(
    current: ThreadMessage[],
    incoming: ThreadMessage[],
    refreshLinksBefore = 0,
): ThreadMessage[] {
    const held = new Map(current.map((message) => [message.id, message]));
    let changed = current.length !== incoming.length;

    const merged = incoming.map((fresh, index) => {
        const before = held.get(fresh.id);

        if (before === undefined) {
            changed = true;

            return fresh;
        }

        const linksFine = before.attachments.every((file) => attachmentKeep(file, refreshLinksBefore));

        if (linksFine && sameMessage(before, fresh) && !before.pending && !before.failed) {
            if (current[index] !== before) {
                changed = true;
            }

            return before;
        }

        changed = true;

        const files = new Map(before.attachments.map((file) => [file.id, file]));

        return {
            ...fresh,
            attachments: fresh.attachments.map((file) => {
                const kept = files.get(file.id);

                return kept !== undefined && attachmentKeep(kept, refreshLinksBefore) ? kept : file;
            }),
        };
    });

    return changed ? merged : current;
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

/**
 * The status arrives as a key, a word and a tone — all three from the server (decision M-14).
 *
 * The panel prints `status_label` inside a `StatusBadge` toned by `status_tone`, so a status here
 * reads exactly as it does on the project page and on a task card. There is no map from a status
 * to a colour in this file and there must not be one.
 */
export interface ConversationContextProject {
    id: number;
    name: string;
    status: string;
    status_label: string | null;
    status_tone: StatusKey | null;
    href: string;
}

export interface ConversationContextTask {
    id: number;
    title: string;
    status: string;
    status_label: string | null;
    status_tone: StatusKey | null;
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
