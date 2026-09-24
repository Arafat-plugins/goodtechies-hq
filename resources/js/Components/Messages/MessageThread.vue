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
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { FILE_ACCEPT, FILE_MAX_LABEL, iconFor, rejectionFor } from '@/Components/Files/files';
import MentionPicker from '@/Components/Messages/MentionPicker.vue';
import MessageBody from '@/Components/Messages/MessageBody.vue';
import type {
    MessagePerson,
    ThreadAttachment,
    ThreadMessage,
    ThreadPayload,
    ThreadRoutes,
} from '@/Components/Messages/messages';
import {
    MESSAGE_MAX_BODY,
    formatDuration,
    formatMessageTime,
    mutateMessage,
    useMentions,
} from '@/Components/Messages/messages';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
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
 * ## Focus, which is the hard part of a chat screen
 *
 * **A new message never takes focus.** It is spoken by a polite live region and the list is
 * scrolled only when the reader was already at the bottom — somebody reading back through
 * yesterday is not yanked to now because a colleague typed. The list itself is one tab stop
 * (`role="log"`, `tabindex="0"`) so a keyboard can reach it and scroll it with the arrow keys
 * without putting a stop on every bubble; the links and attachments inside it are the stops
 * that matter.
 *
 * ## The realtime seam
 *
 * `refresh()` re-reads the whole thread from the server and is the only way messages arrive.
 * A transport that learns a message was posted calls `refresh()`; it never paints a frame it
 * was handed, so what is on screen is always what the policy built. There is **no poll here**:
 * the only timer is the one that re-reads when the signed attachment links are about to lapse,
 * which is the Phase 2 behaviour and stops when the tab is hidden.
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
    const field = bodyEl.value?.$el as HTMLTextAreaElement | undefined;
    const caret = field?.selectionStart ?? null;

    mentions.insert(person, caret);

    void Promise.resolve().then(() => {
        field?.focus();

        const at = body.value.length;

        field?.setSelectionRange?.(at, at);
    });
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

/* ------------------------------------------------------------------ announcing */

/**
 * New messages are spoken by a live region, never focused.
 *
 * Only other people's: a post of your own is already announced by the toaster saying what the
 * server said about it, and DESIGN.md §5.19 allows a message exactly one channel.
 */
const announcement = ref('');

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

            return;
        }

        const payload = (await response.json()) as ThreadPayload;

        if (mine !== token.value) {
            return;
        }

        if (before === null) {
            announce(payload.messages);
            thread.value = payload;
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

watch(
    () => [props.thread.conversation_id, props.thread] as const,
    ([id, payload], [previousId]) => {
        if (id !== previousId) {
            anchor.value = parseAt(payload.last_read_at);
            seen = highestId(payload.messages);
            announcement.value = '';
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
 */
function post(): void {
    if (posting.value || pickedError.value !== null) {
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

function authorOf(message: ThreadMessage): string {
    return message.is_mine ? 'You' : (message.author?.name ?? 'Somebody who has since left');
}

/** An image and a voice note render themselves; everything else is a line with a link on it. */
function rendersInline(file: ThreadAttachment, kind: 'image' | 'voice'): boolean {
    return !linksStale.value && file.kind === kind;
}

const isAnnouncements = computed(() => thread.value.type === 'announcement');
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <div v-if="heading !== null" class="flex min-w-0 flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 class="text-sm font-medium break-words">{{ heading }}</h2>
                <p v-if="description" class="text-xs text-muted-foreground break-words">
                    {{ description }}
                </p>
            </div>
            <!--
                Not disabled while it works: disabling the control somebody just pressed drops
                their focus to the body. `token` already makes a second click harmless.
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

        <!-- New messages are spoken here. Focus stays wherever the reader put it. -->
        <p class="sr-only" aria-live="polite" aria-atomic="true">{{ announcement }}</p>

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
                class="flex flex-col gap-2 rounded-md border bg-muted/40 p-3 sm:flex-row sm:items-center sm:justify-between"
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
                and the links inside it are the stops that actually lead somewhere.
            -->
            <div
                ref="listEl"
                role="log"
                :aria-label="`Messages in ${thread.label}`"
                tabindex="0"
                :class="
                    cn(
                        'min-w-0 rounded-md focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                        scroll && 'max-h-[min(60vh,32rem)] overflow-y-auto pr-1',
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

                <ol v-else class="flex min-w-0 flex-col gap-3">
                    <li v-if="thread.has_more" class="flex justify-center">
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

                    <template v-for="message in thread.messages" :key="message.id">
                        <!--
                            The unread line. A rule and a count, not a tint: it has to read the
                            same to somebody who cannot tell two greys apart.
                        -->
                        <li
                            v-if="message.id === firstUnreadId"
                            class="flex min-w-0 items-center gap-3 pt-1"
                        >
                            <span class="h-px flex-1 bg-border" aria-hidden="true" />
                            <span class="shrink-0 text-xs font-medium text-muted-foreground">
                                {{ unreadCount === 1 ? '1 new message' : `${unreadCount} new messages` }}
                            </span>
                            <span class="h-px flex-1 bg-border" aria-hidden="true" />
                        </li>

                        <li
                            :class="
                                cn(
                                    'flex min-w-0 flex-col gap-2 rounded-md border p-3',
                                    // Whose it is, said by the author line first. The indent
                                    // and the fill are a second and third way of saying the
                                    // same thing, never the only one.
                                    message.is_mine && 'bg-muted/40 sm:ml-6',
                                    // Addressed to the reader. The word in the header line is
                                    // the carrier; the border is the second.
                                    message.mentions_me && 'border-primary',
                                )
                            "
                        >
                            <p class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                <span class="text-sm font-medium break-words">
                                    {{ authorOf(message) }}
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    {{ formatMessageTime(message.created_at) }}
                                </span>
                                <span
                                    v-if="message.mentions_me"
                                    class="text-xs font-medium text-primary"
                                >
                                    Mentions you
                                </span>
                            </p>

                            <MessageBody
                                v-if="message.body"
                                :body="message.body"
                                :mentions="message.mentions"
                            />

                            <ul
                                v-if="message.attachments.length > 0"
                                class="flex min-w-0 flex-col gap-2"
                            >
                                <li
                                    v-for="file in message.attachments"
                                    :key="file.id"
                                    class="flex min-w-0 flex-col gap-1.5 rounded-md border p-2"
                                >
                                    <a
                                        v-if="rendersInline(file, 'image')"
                                        :href="file.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="min-w-0 rounded-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                    >
                                        <img
                                            :src="file.url"
                                            :alt="file.name"
                                            loading="lazy"
                                            class="max-h-64 w-auto max-w-full rounded-sm"
                                        >
                                        <span class="sr-only">(opens in a new tab)</span>
                                    </a>

                                    <!--
                                        Nothing records audio yet. This is here so a voice note
                                        written by the later slice plays, instead of arriving as
                                        a download link nobody expected.
                                    -->
                                    <audio
                                        v-else-if="rendersInline(file, 'voice')"
                                        :src="file.url"
                                        controls
                                        class="w-full min-w-0"
                                    ></audio>

                                    <div class="flex min-w-0 items-center gap-2">
                                        <component
                                            :is="iconFor(file)"
                                            class="size-3.5 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <a
                                            v-if="!linksStale"
                                            :href="file.url"
                                            :target="file.is_previewable ? '_blank' : undefined"
                                            rel="noopener noreferrer"
                                            class="min-w-0 rounded-sm text-xs break-all hover:underline focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                        >
                                            {{ file.name }}
                                            <span v-if="file.is_previewable" class="sr-only">
                                                (opens in a new tab)
                                            </span>
                                        </a>
                                        <span v-else class="min-w-0 text-xs break-all">
                                            {{ file.name }}
                                        </span>
                                        <span class="shrink-0 text-xs text-muted-foreground">
                                            {{ file.size_label }}
                                            <template v-if="formatDuration(file.duration_seconds)">
                                                · {{ formatDuration(file.duration_seconds) }}
                                            </template>
                                        </span>
                                    </div>
                                </li>
                            </ul>
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
                class="flex min-w-0 flex-col gap-2 border-t pt-4"
                novalidate
                @submit.prevent="post"
            >
                <Label :for="bodyId" class="text-xs text-muted-foreground">
                    {{ isAnnouncements ? 'Write an announcement' : 'Write a message' }}
                </Label>

                <Textarea
                    :id="bodyId"
                    ref="bodyEl"
                    v-model="body"
                    :disabled="posting"
                    :aria-describedby="fieldError ? `${errorId} ${hintId}` : hintId"
                    :aria-invalid="fieldError ? true : undefined"
                    rows="3"
                    :placeholder="isAnnouncements ? 'Tell everybody.' : 'Say something.'"
                    class="min-w-0"
                    @keydown.ctrl.enter.prevent="post"
                    @keydown.meta.enter.prevent="post"
                />

                <Label :for="pickerId" class="text-xs text-muted-foreground">
                    Attach a file (optional)
                </Label>
                <input
                    :id="pickerId"
                    ref="pickerEl"
                    type="file"
                    :accept="FILE_ACCEPT"
                    :disabled="posting"
                    :aria-describedby="hintId"
                    class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-xs transition-[color,box-shadow] outline-none file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-2 file:py-1 file:text-sm file:font-medium file:text-secondary-foreground disabled:cursor-not-allowed disabled:opacity-50 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 dark:bg-input/30"
                    @change="choose"
                >

                <p :id="hintId" class="text-xs text-muted-foreground">
                    Up to {{ FILE_MAX_LABEL }} per file.
                    <span :class="remaining < 0 ? 'text-destructive' : undefined">
                        <span class="tabular-nums">{{ remaining }}</span> characters left.
                    </span>
                    Ctrl or ⌘ with Enter sends it.
                </p>

                <p
                    v-if="fieldError"
                    :id="errorId"
                    class="flex items-start gap-2 text-xs text-destructive"
                >
                    <CircleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                    {{ fieldError }}
                </p>

                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <Button type="submit" size="sm" variant="outline" :disabled="posting">
                        <Send aria-hidden="true" />
                        {{ posting ? 'Sending…' : 'Send' }}
                    </Button>

                    <MentionPicker
                        :people="thread.mentionable"
                        :disabled="posting"
                        @pick="mention"
                    />

                    <template v-if="picked">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <Paperclip
                                class="size-3 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 text-xs break-all text-muted-foreground">
                                {{ picked.name }}
                            </span>
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            :disabled="posting"
                            @click="clearPicked"
                        >
                            <X aria-hidden="true" />
                            Remove file
                        </Button>
                    </template>
                </div>
            </form>
        </template>
    </div>
</template>
