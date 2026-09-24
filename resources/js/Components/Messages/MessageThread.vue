<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import {
    ChevronUp,
    CircleAlert,
    MessagesSquare,
    Megaphone,
    Paperclip,
    RefreshCw,
    Send,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { FILE_ACCEPT, FILE_MAX_LABEL, rejectionFor } from '@/Components/Files/files';
import MentionPicker from '@/Components/Messages/MentionPicker.vue';
import MessageRow from '@/Components/Messages/MessageRow.vue';
import {
    THREAD_POLL_MS,
    conversationChannel,
    liveTransportIcon,
    liveTransportLabel,
    liveTransportWord,
    useLiveRefresh,
} from '@/Components/Messages/live';
import type {
    MessagePerson,
    ThreadMessage,
    ThreadPayload,
    ThreadRoutes,
} from '@/Components/Messages/messages';
import {
    MESSAGE_MAX_BODY,
    mutateMessage,
    renderThread,
    threadLayout,
    useMentions,
} from '@/Components/Messages/messages';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * One conversation: the thread oldest-first, an unread line, and a composer with an attachment
 * and an @mention picker.
 *
 * Mounted three times — the Messages page, the project detail's Discussion tab, and (through
 * `TaskDiscussionPanel`) the task detail — which is why it takes ROUTES rather than a surface
 * and an id: a task discussion lives under its task's surface and everything else lives under
 * `/messages`, and a component that knew which world it was in would be two components.
 *
 * ## Who gets a composer is `can_post`, and nothing else
 *
 * It is `ConversationPolicy::post` — the same question the endpoint asks again when a message
 * actually arrives. Nothing here derives it from a role, from `is_mine` or from membership.
 * The announcements channel is the one place it is false for most readers, and that is the
 * policy speaking, not this file.
 *
 * ## Grouping
 *
 * Consecutive messages by one person inside five minutes are one run: the avatar and the name
 * are on the first, and the rest are tight rows whose clock appears on hover or focus. A change
 * of author, a longer gap, a change of day and the unread line each break the run. The decision
 * is made once for the whole list by `renderThread()` rather than per bubble.
 *
 * ## Two layouts, one grouping rule
 *
 * `threadLayout(thread.type)` picks the treatment: a DM is `sided` — two columns of bubbles,
 * no names, no avatars — and every channel is `stacked`, left-aligned with avatars and author
 * lines. It changes only what a row DRAWS. The grouping, the day rules, the unread line, the
 * live regions and `renderThread()` itself are identical in both, which is why a task
 * discussion and a DM stay the same component.
 *
 * ## Focus, which is the hard part of a chat screen
 *
 * **A new message never takes focus.** It is spoken by a polite live region and the list is
 * scrolled only when the reader was already at the bottom — somebody reading back through
 * yesterday is not yanked to now because a colleague typed. The list itself is one tab stop
 * (`role="log"`, `tabindex="0"`) so a keyboard can reach it and scroll it with the arrow keys
 * without putting a stop on every bubble; the links, attachments and the one row action inside
 * it are the stops that matter.
 *
 * ## Enter sends — and it does so on all three screens
 *
 * `Enter` posts, `Shift + Enter` starts a new line, `Ctrl`/`⌘ + Enter` also posts. That is a
 * change from "only Ctrl/⌘ + Enter", it applies to the task discussion too, and the hint under
 * the composer says all three out loud rather than leaving somebody to discover it by losing a
 * paragraph.
 *
 * ## The realtime seam
 *
 * `refresh()` re-reads the whole thread from the server and is the only way messages arrive.
 * A transport that learns a message was posted calls `refresh()`; it never paints a frame it
 * was handed, so what is on screen is always what the policy built.
 *
 * Since POLISH-BACKLOG §A that transport exists and lives in `Messages/live.ts`: on a socket
 * build it subscribes to `conversation.{id}` and calls `load()` on a ping, and on a polling
 * build — which is what the client actually runs — it calls `load()` every ten seconds while
 * the tab is visible. All three mounts get it, because the channel is derived from the
 * payload's own `conversation_id` rather than passed in.
 *
 * **Nothing refreshes behind a hidden tab**, and that is a correctness rule rather than a
 * saving: this component's `GET` is also what marks the thread read, so a hidden tab that kept
 * reading would mark messages read that nobody has looked at.
 *
 * The other timer is older and unrelated: it re-reads when the signed attachment links are
 * about to lapse, which is the Phase 2 behaviour and also stops when the tab is hidden.
 */

const props = withDefaults(
    defineProps<{
        thread: ThreadPayload;
        routes: ThreadRoutes;
        /** Draw the card chrome, or sit bare inside a page that already has some. */
        heading?: string | null;
        description?: string | null;
        /** How tall the scrolling list is. The page gives it a viewport; a panel lets it grow. */
        scroll?: boolean;
    }>(),
    { heading: null, description: null, scroll: false },
);

const emit = defineEmits<{ settled: [] }>();

const uid = useId();
const bodyId = `${uid}-body`;
const pickerId = `${uid}-file`;
const hintId = `${uid}-hint`;
const errorId = `${uid}-error`;

/** The thread on screen: the prop on first paint, this component's own fetch after that. */
const thread = ref<ThreadPayload>(props.thread);

const loadError = ref<string | null>(null);
/**
 * The server has said this conversation is not available (404, which is how this backend says
 * "not for you" everywhere). It is a permanent answer, so the live refresh stops asking —
 * exactly as the bell removes itself on a 403 rather than polling a refusal every 15 seconds.
 */
const threadGone = ref(false);
const refreshing = ref(false);
/** Bumped per fetch so a slow answer for a thread that has moved on cannot land in it. */
const token = ref(0);

const listEl = ref<HTMLElement | null>(null);
const bodyEl = ref<InstanceType<typeof Textarea> | null>(null);

/* ------------------------------------------------------------------ the composer */

const body = ref('');
const picked = ref<File | null>(null);
const pickedError = ref<string | null>(null);
const serverError = ref<string | null>(null);
const posting = ref(false);
const pickerEl = ref<HTMLInputElement | null>(null);

const mentions = useMentions(body);

const fieldError = computed(() => pickedError.value ?? serverError.value);
const remaining = computed(() => MESSAGE_MAX_BODY - body.value.length);

/** The textarea grows with what is typed and then scrolls inside itself. */
const COMPOSER_MAX_PX = 160;

function field(): HTMLTextAreaElement | undefined {
    return bodyEl.value?.$el as HTMLTextAreaElement | undefined;
}

function grow(): void {
    const el = field();

    if (el === undefined) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, COMPOSER_MAX_PX)}px`;
}

function clearPicked(): void {
    picked.value = null;
    pickedError.value = null;

    if (pickerEl.value) {
        // A file input's value is not bound, so clearing the model is not clearing the field.
        pickerEl.value.value = '';
    }
}

function resetComposer(): void {
    body.value = '';
    serverError.value = null;
    mentions.reset();
    clearPicked();

    void nextTick(grow);
}

function choose(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    picked.value = file;
    serverError.value = null;
    pickedError.value = file === null ? null : rejectionFor(file);
}

/**
 * A name was chosen: put `@Name` where the caret was and give the caret straight back.
 *
 * Returning focus to the textarea rather than to the trigger is the one place this control
 * departs from "an overlay returns focus to whatever opened it", and it is deliberate: the
 * reader was writing a sentence and the picker interrupted it. The rule's intent is that focus
 * never lands nowhere; it lands where the work is.
 */
function mention(person: MessagePerson): void {
    const el = field();
    const caret = el?.selectionStart ?? null;

    mentions.insert(person, caret);

    void Promise.resolve().then(() => {
        el?.focus();

        const at = body.value.length;

        el?.setSelectionRange?.(at, at);
        grow();
    });
}

/**
 * Enter sends; Shift + Enter is a newline.
 *
 * `isComposing` is checked because an IME's own Enter — the one that accepts a candidate — is
 * delivered here as a keydown, and a composer that posted on it would cut every Bangla or
 * Japanese sentence in half at its first word.
 */
function onEnter(event: KeyboardEvent): void {
    if (event.isComposing) {
        return;
    }

    // Shift is the newline, unless a modifier that means "send" is also down. One handler for
    // all three shortcuts, because three handlers on the same key posted the message twice.
    if (event.shiftKey && !event.ctrlKey && !event.metaKey) {
        return;
    }

    event.preventDefault();
    post();
}

/* ------------------------------------------------------------------ the unread line */

function parseAt(value: string | null | undefined): number | null {
    if (!value) {
        return null;
    }

    const at = Date.parse(value);

    return Number.isNaN(at) ? null : at;
}

/** Where this reader's line sat when the thread opened. Deliberately old: opening marks read. */
const anchor = ref<number | null>(parseAt(props.thread.last_read_at));

function isUnread(message: ThreadMessage): boolean {
    if (message.is_mine) {
        return false;
    }

    if (anchor.value === null) {
        return true;
    }

    const at = parseAt(message.created_at);

    return at !== null && at > anchor.value;
}

const unreadCount = computed(() => thread.value.messages.filter(isUnread).length);
const firstUnreadId = computed(() => thread.value.messages.find(isUnread)?.id ?? null);

/** Every message, with what it needs to know about its neighbours to be drawn. */
const rendered = computed(() => renderThread(thread.value.messages, firstUnreadId.value));

/**
 * `sided` for a DM, `stacked` for every channel. Read from the payload's own `type`, so the
 * three mounts of this component do not each have to know what they are showing.
 */
const layout = computed(() => threadLayout(thread.value.type));

/* ------------------------------------------------------------------ announcing */

/**
 * New messages are spoken by a live region, never focused.
 *
 * Only other people's: a post of your own is already announced by the toaster saying what the
 * server said about it, and DESIGN.md §5.19 allows a message exactly one channel.
 */
const announcement = ref('');

/** A second region, for what the reader just DID — copying a message. Never a new message. */
const actionStatus = ref('');

let seen = highestId(props.thread.messages);

function highestId(messages: ThreadMessage[]): number {
    return messages.reduce((top, message) => Math.max(top, message.id), 0);
}

function announce(messages: ThreadMessage[]): void {
    const fresh = messages.filter((message) => message.id > seen && !message.is_mine);

    seen = Math.max(seen, highestId(messages));

    if (fresh.length === 0) {
        return;
    }

    const named = fresh.some((message) => message.mentions_me);

    announcement.value = `${fresh.length === 1 ? '1 new message' : `${fresh.length} new messages`}${
        named ? ', one of them mentioning you' : ''
    }.`;
}

/* ------------------------------------------------------------------ scrolling */

const AT_BOTTOM_MARGIN = 48;

function atBottom(): boolean {
    const el = listEl.value;

    if (el === null) {
        return true;
    }

    return el.scrollHeight - el.scrollTop - el.clientHeight <= AT_BOTTOM_MARGIN;
}

/** Only if they were already there. Somebody reading back through yesterday stays there. */
function keepAtBottom(wasAtBottom: boolean): void {
    if (!wasAtBottom) {
        return;
    }

    void Promise.resolve().then(() => {
        const el = listEl.value;

        if (el !== null) {
            el.scrollTop = el.scrollHeight;
        }
    });
}

/* ------------------------------------------------------------------ reading */

/**
 * A refresh must not throw away history the reader asked for.
 *
 * `GET` returns the newest window. Before this slice that was the only way a thread was ever
 * re-read — by hand, by a post, or when the links lapsed — and replacing the list was harmless
 * because the reader had just asked for it. Now it happens every ten seconds, and a reader who
 * pressed "Load earlier messages" and is sitting up in yesterday would have the ground taken
 * out from under them: the list would shrink back to the newest window and the scroll position
 * would land somewhere else entirely.
 *
 * So anything already loaded that is older than the fresh window is kept in front of it, and
 * `has_more` stays the answer that belongs to the OLDEST message on screen rather than the one
 * the newest window came with.
 */
function keepLoadedHistory(current: ThreadPayload, payload: ThreadPayload): ThreadPayload {
    const oldest = payload.messages[0]?.id ?? null;

    if (oldest === null) {
        return payload;
    }

    const earlier = current.messages.filter((message) => message.id < oldest);

    if (earlier.length === 0) {
        return payload;
    }

    return { ...payload, messages: [...earlier, ...payload.messages], has_more: current.has_more };
}

async function load(before: number | null = null): Promise<void> {
    const mine = ++token.value;
    const wasAtBottom = before === null && atBottom();

    refreshing.value = true;

    try {
        const url = before === null ? props.routes.thread : `${props.routes.thread}?before=${before}`;

        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (mine !== token.value) {
            return;
        }

        if (!response.ok) {
            // 404 is "you may not see this conversation", said the way the backend says it
            // everywhere. There is no 403 to tell it apart from.
            loadError.value = response.status === 404
                ? 'This conversation is not available.'
                : 'The conversation could not be loaded.';

            // A refusal is not a transient failure, so the live refresh stops asking. Anything
            // else — a dropped connection, a 500 — is worth the next tick.
            threadGone.value = response.status === 404;

            return;
        }

        const payload = (await response.json()) as ThreadPayload;

        if (mine !== token.value) {
            return;
        }

        if (before === null) {
            announce(payload.messages);
            thread.value = keepLoadedHistory(thread.value, payload);
            keepAtBottom(wasAtBottom);
        } else {
            // Older history, prepended. `seen` is untouched: nothing here is new.
            thread.value = {
                ...payload,
                messages: [...payload.messages, ...thread.value.messages],
                has_more: payload.has_more,
            };
        }

        loadError.value = null;
    } catch {
        if (mine === token.value) {
            loadError.value = 'The conversation could not be loaded.';
        }
    } finally {
        if (mine === token.value) {
            refreshing.value = false;

            if (before === null) {
                // This thread has just re-read on its own account — on mount, by hand, or
                // because a post landed. The interval restarts from now, so a post is never
                // followed a second later by a poll asking the same question.
                live.markFresh();
            }
        }
    }
}

function loadEarlier(): void {
    const oldest = thread.value.messages[0]?.id ?? null;

    if (oldest !== null) {
        void load(oldest);
    }
}

/** What a realtime transport calls. It never paints a frame it was handed. */
defineExpose({ refresh: () => load() });

/* ------------------------------------------------------------------ keeping current */

/**
 * The transport, for all three mounts at once.
 *
 * The channel is derived from the payload's own `conversation_id`, which this component has
 * had since Phase 6 — so the Messages page, the project Discussion tab and the task panel all
 * gain this without passing anything new, and a comment on a task appears in the panel exactly
 * the way a DM appears on the Messages page. `defineExpose` is untouched: `refresh` is still
 * the one method, and this is simply the first thing in the application that calls it.
 *
 * `canRefresh` keeps the automatic half out of two situations the manual half was never in: a
 * post that is in flight (it re-reads by itself when it lands, and a second read racing it
 * would only decide the winner by network timing), and a conversation the server has already
 * refused.
 */
const live = useLiveRefresh(
    () => conversationChannel(thread.value.conversation_id),
    () => void load(),
    {
        intervalMs: THREAD_POLL_MS,
        canRefresh: () => !posting.value && !threadGone.value,
    },
);

const liveWord = computed(() => liveTransportWord(live.transport.value, THREAD_POLL_MS));
const liveLabel = computed(() => liveTransportLabel(live.transport.value, THREAD_POLL_MS));
const liveIcon = computed(() => liveTransportIcon(live.transport.value));

watch(
    () => [props.thread.conversation_id, props.thread] as const,
    ([id, payload], [previousId]) => {
        if (id !== previousId) {
            anchor.value = parseAt(payload.last_read_at);
            seen = highestId(payload.messages);
            announcement.value = '';
            actionStatus.value = '';
            loadError.value = null;
            resetComposer();
            thread.value = payload;

            void load();

            return;
        }

        const wasAtBottom = atBottom();

        announce(payload.messages);
        thread.value = payload;
        keepAtBottom(wasAtBottom);
    },
);

/* -------------------------------------------------------- the links, and their expiry */

/**
 * A signed URL is not a bearer token — `FilePolicy::view` runs on every fetch — but it does
 * lapse, and a link the screen knows is dead is worse than an honest refusal. So the THREAD is
 * re-read rather than a URL rebuilt: minting lives on the server and only there.
 */
const EXPIRY_MARGIN_MS = 30_000;
const TICK_MS = 15_000;

const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;

const attachmentCount = computed(
    () => thread.value.messages.reduce((total, message) => total + message.attachments.length, 0),
);

const expiresAt = computed(() => {
    let earliest: number | null = null;

    for (const message of thread.value.messages) {
        for (const file of message.attachments) {
            const at = parseAt(file.url_expires_at);

            if (at !== null && (earliest === null || at < earliest)) {
                earliest = at;
            }
        }
    }

    return earliest;
});

const linksStale = computed(
    () => expiresAt.value !== null && now.value >= expiresAt.value - EXPIRY_MARGIN_MS,
);

/** Only while somebody is looking: a background tab does not need fresh signatures all day. */
function refreshIfStale(): void {
    now.value = Date.now();

    if (linksStale.value && !posting.value && document.visibilityState === 'visible') {
        void load();
    }
}

onMounted(() => {
    // Opening the thread is what marks it read, so this runs even though the messages are
    // already in hand. `anchor` was taken before it, which keeps the line on screen.
    void load();

    keepAtBottom(true);
    grow();

    ticker = setInterval(refreshIfStale, TICK_MS);
    document.addEventListener('visibilitychange', refreshIfStale);
});

onBeforeUnmount(() => {
    clearInterval(ticker);
    document.removeEventListener('visibilitychange', refreshIfStale);
});

/* ------------------------------------------------------------------ writing */

/**
 * Post it.
 *
 * Deliberately not blocked on an empty composer: "neither text nor a file" is a rule
 * `StoreMessageRequest` states in a sentence of its own, and the sentence the reader should get
 * is that one rather than a disabled button that explains nothing.
 *
 * It IS blocked on `can_post`, which is the server's answer and the only thing allowed to say
 * yes — a keyboard shortcut must not reach an endpoint the composer itself is not drawn for.
 */
function post(): void {
    if (!thread.value.can_post || posting.value || pickedError.value !== null) {
        return;
    }

    posting.value = true;
    serverError.value = null;

    const file = picked.value;
    const named = mentions.ids();
    const data: Record<string, FormDataConvertible> = { body: body.value };

    named.forEach((id, index) => {
        data[`mentions[${index}]`] = id;
    });

    if (file !== null) {
        data.file = file;
    }

    mutateMessage(props.routes.store, data, {
        forceFormData: file !== null,
        onAccepted: () => {
            resetComposer();
            // Posting marked the thread read server-side; this is what brings the message back
            // with its author, its attachment and a live signed URL on it.
            void load();
            emit('settled');
        },
        onInvalid: (errors) => {
            // Both halves of `required_without` carry the same sentence, so either will do.
            serverError.value = (errors.body ?? errors.file ?? errors.mentions ?? 'That message was refused.') as string;
        },
        onFinish: () => {
            posting.value = false;
        },
    });
}

/* ------------------------------------------------------------------ presentation */

const isAnnouncements = computed(() => thread.value.type === 'announcement');
</script>

<template>
    <div
        :class="
            cn(
                'flex h-full min-h-0 min-w-0 flex-col gap-3',
                // An auto-height parent — the project Discussion tab, the task panel — gets a
                // ceiling so the thread cannot become the whole page. A parent with a definite
                // height (the Messages page) lifts it with `lg:max-h-none` from outside.
                scroll && 'max-h-[min(68svh,40rem)]',
            )
        "
    >
        <div v-if="heading !== null" class="flex min-w-0 flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 class="text-sm font-medium break-words">{{ heading }}</h2>
                <p v-if="description" class="text-xs text-muted-foreground break-words">
                    {{ description }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <!--
                    How this thread is keeping itself current, said quietly and said honestly.
                    The word is the fact — a screen reader always gets the whole sentence, and
                    from `sm` the short form is on screen — so nothing here is carried by the
                    icon or by colour alone (DESIGN.md §5.6). It is **never** "Live" on a
                    polling build.
                -->
                <span
                    class="inline-flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
                    :title="liveLabel"
                >
                    <component :is="liveIcon" class="size-3.5 shrink-0" aria-hidden="true" />
                    <span aria-hidden="true" class="hidden sm:inline">{{ liveWord }}</span>
                    <span class="sr-only">{{ liveLabel }}</span>
                </span>

                <!--
                    Not disabled while it works: disabling the control somebody just pressed
                    drops their focus to the body. `token` already makes a second click
                    harmless.
                -->
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    class="shrink-0"
                    :aria-busy="refreshing || undefined"
                    aria-label="Refresh this conversation"
                    @click="load()"
                >
                    <RefreshCw :class="refreshing && 'animate-spin'" aria-hidden="true" />
                </Button>
            </div>
        </div>

        <!-- New messages are spoken here. Focus stays wherever the reader put it. -->
        <p class="sr-only" aria-live="polite" aria-atomic="true">{{ announcement }}</p>
        <!-- And what the reader just did, which is a different sentence on a different channel. -->
        <p class="sr-only" aria-live="polite" aria-atomic="true">{{ actionStatus }}</p>

        <EmptyState
            v-if="loadError"
            :icon="CircleAlert"
            variant="error"
            title="Conversation could not be loaded"
            :description="loadError"
        >
            <template #action>
                <Button type="button" variant="outline" size="sm" @click="load()">
                    <RefreshCw aria-hidden="true" />
                    Try again
                </Button>
            </template>
        </EmptyState>

        <template v-else>
            <div
                v-if="linksStale && attachmentCount > 0"
                class="flex shrink-0 flex-col gap-2 rounded-md border bg-muted/40 p-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    The attachment links in this conversation have expired.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="shrink-0"
                    :aria-busy="refreshing || undefined"
                    @click="load()"
                >
                    <RefreshCw aria-hidden="true" />
                    Refresh links
                </Button>
            </div>

            <!--
                One tab stop for the whole thread, not one per bubble: `role="log"` tells a
                screen reader what it is, `tabindex="0"` lets a keyboard reach and scroll it,
                and the links and the one row action inside it are the stops that actually lead
                somewhere.
            -->
            <div
                ref="listEl"
                role="log"
                :aria-label="`Messages in ${thread.label}`"
                tabindex="0"
                :class="
                    cn(
                        'min-w-0 rounded-md focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                        scroll && 'min-h-0 flex-1 overflow-y-auto pr-1',
                    )
                "
            >
                <EmptyState
                    v-if="thread.messages.length === 0"
                    :icon="isAnnouncements ? Megaphone : MessagesSquare"
                    title="Nothing said yet"
                    :description="
                        thread.can_post
                            ? 'Say the thing somebody will need tomorrow morning.'
                            : 'Anything said here shows up in this list.'
                    "
                />

                <ol v-else class="flex min-w-0 flex-col">
                    <li v-if="thread.has_more" class="flex justify-center pb-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-busy="refreshing || undefined"
                            @click="loadEarlier"
                        >
                            <ChevronUp aria-hidden="true" />
                            Load earlier messages
                        </Button>
                    </li>

                    <template v-for="entry in rendered" :key="entry.message.id">
                        <!-- The day rule. A word, so it reads the same without colour. -->
                        <li
                            v-if="entry.dayLabel"
                            class="flex min-w-0 items-center gap-3 py-3"
                        >
                            <span class="h-px flex-1 bg-border" aria-hidden="true" />
                            <span class="shrink-0 text-xs font-medium text-muted-foreground">
                                {{ entry.dayLabel }}
                            </span>
                            <span class="h-px flex-1 bg-border" aria-hidden="true" />
                        </li>

                        <!--
                            The unread line. A rule and a count, not a tint: it has to read the
                            same to somebody who cannot tell two greys apart.
                        -->
                        <li
                            v-if="entry.unreadLine"
                            class="flex min-w-0 items-center gap-3 py-2"
                        >
                            <span class="h-px flex-1 bg-primary" aria-hidden="true" />
                            <span class="shrink-0 text-xs font-medium text-primary">
                                {{ unreadCount === 1 ? '1 new message' : `${unreadCount} new messages` }}
                            </span>
                            <span class="h-px flex-1 bg-primary" aria-hidden="true" />
                        </li>

                        <li class="min-w-0">
                            <MessageRow
                                :message="entry.message"
                                :starts-run="entry.startsRun"
                                :links-stale="linksStale"
                                :layout="layout"
                                @announce="actionStatus = $event"
                            />
                        </li>
                    </template>
                </ol>
            </div>

            <!--
                `can_post`, and nothing else. The announcements channel is where it is false for
                most people, which is ConversationPolicy asking for `announcements.send` — not a
                role read in this file.
            -->
            <form
                v-if="thread.can_post"
                class="flex min-w-0 shrink-0 flex-col gap-2 border-t pt-3"
                novalidate
                @submit.prevent="post"
            >
                <Label :for="bodyId" class="sr-only">
                    {{ isAnnouncements ? 'Write an announcement' : 'Write a message' }}
                </Label>

                <Textarea
                    :id="bodyId"
                    ref="bodyEl"
                    v-model="body"
                    :disabled="posting"
                    :aria-describedby="fieldError ? `${errorId} ${hintId}` : hintId"
                    :aria-invalid="fieldError ? true : undefined"
                    rows="1"
                    :placeholder="isAnnouncements ? 'Tell everybody.' : 'Say something.'"
                    class="max-h-40 min-h-9 min-w-0 resize-none overflow-y-auto py-2"
                    @input="grow"
                    @keydown.enter="onEnter"
                />

                <!-- The picker itself is off-screen; the paperclip is the control. -->
                <Label :for="pickerId" class="sr-only">Attach a file</Label>
                <input
                    :id="pickerId"
                    ref="pickerEl"
                    type="file"
                    :accept="FILE_ACCEPT"
                    :disabled="posting"
                    :aria-describedby="hintId"
                    class="sr-only"
                    @change="choose"
                >

                <!-- The picked file, with the control that unpicks it. -->
                <div
                    v-if="picked"
                    class="flex min-w-0 items-center gap-2 self-start rounded-md border bg-muted/40 py-1 pr-1 pl-2"
                >
                    <Paperclip class="size-3 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span class="min-w-0 truncate text-xs" :title="picked.name">
                        {{ picked.name }}
                    </span>
                    <Button
                        type="button"
                        size="icon-xs"
                        variant="ghost"
                        :disabled="posting"
                        :aria-label="`Remove ${picked.name}`"
                        @click="clearPicked"
                    >
                        <X aria-hidden="true" />
                    </Button>
                </div>

                <p
                    v-if="fieldError"
                    :id="errorId"
                    class="flex items-start gap-2 text-xs text-destructive"
                >
                    <CircleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                    {{ fieldError }}
                </p>

                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <TooltipProvider :delay-duration="150">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    :disabled="posting"
                                    aria-label="Attach a file"
                                    @click="pickerEl?.click()"
                                >
                                    <Paperclip aria-hidden="true" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>Attach a file</TooltipContent>
                        </Tooltip>
                    </TooltipProvider>

                    <MentionPicker
                        :people="thread.mentionable"
                        :disabled="posting"
                        @pick="mention"
                    />

                    <span class="flex-1" aria-hidden="true" />

                    <Button type="submit" size="sm" class="shrink-0" :disabled="posting">
                        <Send aria-hidden="true" />
                        {{ posting ? 'Sending…' : 'Send' }}
                    </Button>
                </div>

                <!--
                    The shortcut is stated rather than discovered. Enter sending is a CHANGE, it
                    applies to the task discussion too, and somebody who finds it out by losing a
                    half-written paragraph has been told by the wrong teacher.
                -->
                <p :id="hintId" class="min-w-0 text-xs text-muted-foreground">
                    Enter sends · Shift + Enter starts a new line · Ctrl or ⌘ with Enter also
                    sends · up to {{ FILE_MAX_LABEL }} per file ·
                    <span :class="remaining < 0 ? 'text-destructive' : undefined">
                        <span class="tabular-nums">{{ remaining }}</span> characters left
                    </span>
                </p>
            </form>
        </template>
    </div>
</template>
