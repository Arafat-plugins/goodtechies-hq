<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { CircleAlert, MessagesSquare, Paperclip, RefreshCw, Send, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { FILE_ACCEPT, FILE_MAX_LABEL, iconFor, rejectionFor } from '@/Components/Files/files';
import type {
    TaskDetail,
    TaskDiscussion,
    TaskMessage,
    TaskMessageAttachment,
    TaskSurface,
} from '@/Components/Tasks/taskDetail';
import {
    TASK_MESSAGE_MAX_BODY,
    formatDateTime,
    mutateTask,
    taskRoutes,
} from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

/**
 * The task's discussion: the thread, and a composer that can carry a file.
 *
 * **Who gets a composer is `can_post` and nothing else.** It is `ConversationPolicy::post`,
 * which delegates to `TaskPolicy::view` — the same question the endpoint asks again when a
 * message actually arrives. Nothing here derives it from a role, from `is_mine` or from
 * membership: `conversation_members` is read state and grants nobody anything, which is what
 * lets a reassignment close the door with no sync having run.
 *
 * **Why it both receives a thread and fetches one.** The detail payload inlines the discussion
 * (`BuildsDiscussionPayload`), so the panel paints with its messages rather than with a
 * spinner. It still calls `GET …/discussion`, for the two things props cannot do: that GET is
 * what marks the thread read, and it is what mints fresh signed URLs once the ones in hand have
 * lapsed.
 *
 * **Why the unread line is frozen.** Marking read is the first thing opening the panel does, so
 * a heartbeat later the server's honest answer is "nothing unread". The line is therefore taken
 * from the first payload this panel saw for this task and kept — "you were here" has to survive
 * the act of looking.
 */

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
    discussion: TaskDiscussion;
}>();

/** A message landed, so the task's activity trail has a new line on it. */
const emit = defineEmits<{ settled: [] }>();

const uid = useId();
const bodyId = `${uid}-body`;
const pickerId = `${uid}-file`;
const hintId = `${uid}-hint`;
const errorId = `${uid}-error`;

const routes = computed(() => taskRoutes(props.surface, props.task.id));

/**
 * The thread this panel is showing: the prop on first paint, its own fetch after that.
 *
 * Both mounts keep it fresh without knowing they are doing it. On the page mount a post lands
 * `back()` on the detail route, so Inertia hands down a new `discussion`; on the drawer mount
 * the parent re-reads the detail when this panel settles. Either way it arrives here as a prop.
 */
const thread = ref<TaskDiscussion>(props.discussion);

const loadError = ref<string | null>(null);
const refreshing = ref(false);
/** Bumped per fetch so a slow answer for a thread that has moved on cannot land in it. */
const token = ref(0);

/* ------------------------------------------------------------------ the composer */

const body = ref('');
const picked = ref<File | null>(null);
const pickedError = ref<string | null>(null);
/** Whatever the server said about the field, rendered under it unchanged. */
const serverError = ref<string | null>(null);
const posting = ref(false);
const pickerEl = ref<HTMLInputElement | null>(null);

const fieldError = computed(() => pickedError.value ?? serverError.value);
const remaining = computed(() => TASK_MESSAGE_MAX_BODY - body.value.length);

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
    clearPicked();
}

function choose(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    picked.value = file;
    serverError.value = null;
    pickedError.value = file === null ? null : rejectionFor(file);
}

/* ------------------------------------------------------------------ the unread line */

function parseAt(value: string | null | undefined): number | null {
    if (!value) {
        return null;
    }

    const at = Date.parse(value);

    return Number.isNaN(at) ? null : at;
}

/** Where this reader's line sat when the panel opened. See the header: it is deliberately old. */
const anchor = ref<number | null>(parseAt(props.discussion.last_read_at));

/**
 * Somebody else's message, after this reader's line.
 *
 * Their own are excluded for the same reason `ConversationService::readState()` excludes them:
 * a count that goes up when you post is measuring the wrong thing. A null anchor means they
 * have never opened this discussion, so everything anybody else wrote is new.
 */
function isUnread(message: TaskMessage): boolean {
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

/** The divider goes above the first of them, and only there. */
const firstUnreadId = computed(() => thread.value.messages.find(isUnread)?.id ?? null);

/* ------------------------------------------------------------------ announcing */

/**
 * New messages are spoken by a live region, never focused.
 *
 * Only other people's: a post of your own is already announced by the toaster saying what the
 * server said about it, and DESIGN.md §5.19 allows a message exactly one channel.
 */
const announcement = ref('');

/** The newest id already accounted for, so a refresh reports only what it actually brought. */
let seen = highestId(props.discussion.messages);

function highestId(messages: TaskMessage[]): number {
    return messages.reduce((top, message) => Math.max(top, message.id), 0);
}

function announce(messages: TaskMessage[]): void {
    const fresh = messages.filter((message) => message.id > seen && !message.is_mine).length;

    seen = Math.max(seen, highestId(messages));

    if (fresh > 0) {
        announcement.value = fresh === 1
            ? '1 new message in the discussion.'
            : `${fresh} new messages in the discussion.`;
    }
}

/* ------------------------------------------------------------------ reading */

async function load(): Promise<void> {
    const mine = ++token.value;

    refreshing.value = true;

    try {
        const response = await fetch(routes.value.discussion, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (mine !== token.value) {
            return;
        }

        if (!response.ok) {
            // 404 is "you may not see the task this hangs off", said the way the backend says
            // it everywhere. There is no 403 to tell it apart from.
            loadError.value = response.status === 404
                ? 'This discussion is not available.'
                : 'The discussion could not be loaded.';

            return;
        }

        const payload = (await response.json()) as TaskDiscussion;

        if (mine !== token.value) {
            return;
        }

        announce(payload.messages);
        thread.value = payload;
        loadError.value = null;
    } catch {
        if (mine === token.value) {
            loadError.value = 'The discussion could not be loaded.';
        }
    } finally {
        if (mine === token.value) {
            refreshing.value = false;
        }
    }
}

/**
 * One watcher for both things a new prop can mean.
 *
 * A fresh payload for the SAME task is a refresh, and whatever it brought is announced. A
 * different task is the drawer being reused, and everything that was about the old one — the
 * reader's line, what has been announced, a half-typed message — goes with it.
 */
watch(
    () => [props.task.id, props.discussion] as const,
    ([id, discussion], [previousId]) => {
        if (id !== previousId) {
            anchor.value = parseAt(discussion.last_read_at);
            seen = highestId(discussion.messages);
            announcement.value = '';
            loadError.value = null;
            resetComposer();
            thread.value = discussion;

            void load();

            return;
        }

        announce(discussion.messages);
        thread.value = discussion;
    },
);

/* ------------------------------------------------------- the links, and their expiry */

/**
 * A signed URL is not a bearer token — `FilePolicy::view` runs on every fetch — but it does
 * lapse, and a link the panel knows is dead is worse than an honest refusal. So the THREAD is
 * re-read rather than a URL rebuilt: minting lives on the server and only there.
 */
const EXPIRY_MARGIN_MS = 30_000;
const TICK_MS = 15_000;

const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;

const attachmentCount = computed(
    () => thread.value.messages.reduce((total, message) => total + message.attachments.length, 0),
);

/** The whole thread is signed in one request, so the earliest expiry is the thread's expiry. */
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
    // Opening the panel is what marks it read, so this runs even though the thread is already
    // in hand. `anchor` was taken before it, which is what keeps the line on screen.
    void load();

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
 * is that one rather than a disabled button that explains nothing. The request goes, the server
 * refuses it, and its words land under the field.
 */
function post(): void {
    if (posting.value || pickedError.value !== null) {
        return;
    }

    posting.value = true;
    serverError.value = null;

    const file = picked.value;
    const data: Record<string, FormDataConvertible> = { body: body.value };

    if (file !== null) {
        data.file = file;
    }

    mutateTask('post', routes.value.discussion, data, {
        forceFormData: file !== null,
        onAccepted: () => {
            resetComposer();
            // Posting marked the thread read server-side; this is what brings the message back
            // with its author, its attachment and a live signed URL on it.
            void load();
        },
        onInvalid: (errors) => {
            // Both halves of `required_without` carry the same sentence, so either will do.
            serverError.value = errors.body ?? errors.file ?? 'That message was refused.';
        },
        // A message writes a line into the task's activity trail, which the rest of the screen
        // is showing. The drawer re-reads the detail here; the page mount already has it.
        onSettled: () => emit('settled'),
        onFinish: () => {
            posting.value = false;
        },
    });
}

/* ------------------------------------------------------------------ presentation */

/** A voice note's length. Phase 6 records them; this is here so one renders if it arrives. */
function formatDuration(seconds: number | null): string {
    if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) {
        return '';
    }

    const minutes = Math.floor(seconds / 60);

    return `${minutes}:${String(Math.round(seconds % 60)).padStart(2, '0')}`;
}

function authorOf(message: TaskMessage): string {
    return message.is_mine ? 'You' : (message.author?.name ?? 'Somebody who has since left');
}

/** An image and a voice note render themselves; everything else is a line with a link on it. */
function rendersInline(file: TaskMessageAttachment, kind: 'image' | 'voice'): boolean {
    return !linksStale.value && file.kind === kind;
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Discussion</CardTitle>
            <CardDescription>
                Questions and answers about this task, oldest first.
            </CardDescription>
            <CardAction>
                <!--
                    Not disabled while it works: disabling the control somebody just pressed
                    drops their focus to the body, and they have to find their way back into
                    the panel. `token` already makes a second click harmless.
                -->
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :aria-busy="refreshing || undefined"
                    aria-label="Refresh the discussion"
                    @click="load"
                >
                    <RefreshCw :class="refreshing && 'animate-spin'" aria-hidden="true" />
                </Button>
            </CardAction>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-4">
            <!-- New messages are spoken here. Focus stays wherever the reader put it. -->
            <p class="sr-only" aria-live="polite" aria-atomic="true">{{ announcement }}</p>

            <EmptyState
                v-if="loadError"
                :icon="CircleAlert"
                variant="error"
                title="Discussion could not be loaded"
                :description="loadError"
            >
                <template #action>
                    <Button type="button" variant="outline" size="sm" @click="load">
                        <RefreshCw aria-hidden="true" />
                        Try again
                    </Button>
                </template>
            </EmptyState>

            <template v-else>
                <!--
                    Every attachment link in the thread expired together. Nothing is offered as
                    a download while that is true; the thread is re-read instead.
                -->
                <div
                    v-if="linksStale && attachmentCount > 0"
                    class="flex flex-col gap-2 rounded-md border bg-muted/40 p-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-muted-foreground">
                        The attachment links in this thread have expired.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="shrink-0"
                        :aria-busy="refreshing || undefined"
                        @click="load"
                    >
                        <RefreshCw aria-hidden="true" />
                        Refresh links
                    </Button>
                </div>

                <EmptyState
                    v-if="thread.messages.length === 0"
                    :icon="MessagesSquare"
                    title="Nothing said yet"
                    :description="
                        thread.can_post
                            ? 'Ask a question, or leave the answer somebody needs tomorrow morning.'
                            : 'Anything said about this task shows up here.'
                    "
                />

                <ol v-else class="flex min-w-0 flex-col gap-3">
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
                                    // Whose it is, said by the author line first. The indent and
                                    // the fill are a second and third way of saying the same
                                    // thing, never the only one.
                                    message.is_mine && 'bg-muted/40 sm:ml-6',
                                )
                            "
                        >
                            <p class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                <span class="text-sm font-medium break-words">
                                    {{ authorOf(message) }}
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    {{ formatDateTime(message.created_at) }}
                                </span>
                            </p>

                            <!--
                                `break-words` plus `whitespace-pre-line`: the line breaks
                                somebody typed survive, and a pasted URL with nothing to break
                                on wraps rather than widening the drawer.
                            -->
                            <p
                                v-if="message.body"
                                class="min-w-0 text-sm break-words whitespace-pre-line"
                            >
                                {{ message.body }}
                            </p>

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
                                        written by a later phase plays, instead of arriving as a
                                        download link nobody expected.
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

                <!--
                    `can_post`, and nothing else. An archived task still gets one, deliberately:
                    ConversationPolicy::post is not gated on TaskPolicy::update, because saying
                    "this was archived by mistake" is more use than being unable to.
                -->
                <form
                    v-if="thread.can_post"
                    class="flex min-w-0 flex-col gap-2 border-t pt-4"
                    novalidate
                    @submit.prevent="post"
                >
                    <Label :for="bodyId" class="text-xs text-muted-foreground">Write a message</Label>

                    <Textarea
                        :id="bodyId"
                        v-model="body"
                        :disabled="posting"
                        :aria-describedby="fieldError ? `${errorId} ${hintId}` : hintId"
                        :aria-invalid="fieldError ? true : undefined"
                        rows="3"
                        placeholder="Ask something, or answer."
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
                        Ctrl or ⌘ with Enter posts it.
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
                            {{ posting ? 'Posting…' : 'Post' }}
                        </Button>
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
        </CardContent>
    </Card>
</template>
