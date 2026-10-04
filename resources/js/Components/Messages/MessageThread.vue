<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    ChevronUp,
    CircleAlert,
    ImagePlus,
    Loader2,
    MessagesSquare,
    Megaphone,
    Paperclip,
    RefreshCw,
    Send,
    X,
} from '@lucide/vue';
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { DRAWER_FOOTER_INSET } from '@/Components/drawerFooter';
import EmptyState from '@/Components/EmptyState.vue';
import EmojiPicker from '@/Components/Messages/EmojiPicker.vue';
import { rememberEmoji } from '@/Components/Messages/emoji';
import MentionPicker from '@/Components/Messages/MentionPicker.vue';
import MessageRow from '@/Components/Messages/MessageRow.vue';
import { rememberThread } from '@/Components/Messages/opening';
import ReplyQuote from '@/Components/Messages/ReplyQuote.vue';
import VoiceRecorder from '@/Components/Messages/VoiceRecorder.vue';
import LiveIndicator from '@/Components/Realtime/LiveIndicator.vue';
import {
    adoptConversationUnread,
    isViewingConversation,
    setViewingConversation,
} from '@/Components/Realtime/shell';
import {
    THREAD_POLL_MS,
    conversationChannel,
    useLiveRefresh,
} from '@/Components/Realtime/live';
import type {
    MessagePerson,
    ThreadMessage,
    ThreadReplyTo,
    ThreadPayload,
    ThreadRoutes,
} from '@/Components/Messages/messages';
import {
    MESSAGE_FILE_ACCEPT,
    clipboardImage,
    mergeThreadMessages,
    messageFileRejection,
    pastedFileName,
    refusalText,
    replyReference,
    renderThread,
    sendMessage,
    threadLayout,
    useMentions,
} from '@/Components/Messages/messages';
import type { VoiceClip } from '@/Components/Messages/voice';
import { clipFile } from '@/Components/Messages/voice';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Progress } from '@/Components/ui/progress';
import { Textarea } from '@/Components/ui/textarea';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { clearDraft, messageDraftKey, readDraft, writeDraft } from '@/lib/drafts';
import { windowFocused } from '@/lib/attention';
import { backoff, fetchWithTimeout, reportNetworkFailure, reportNetworkSuccess, TOO_LARGE_TEXT } from '@/lib/net';
import { playMessageSent } from '@/lib/sound';
import { isSessionLive, reportResponse, sessionState } from '@/lib/session';
import { useUnsavedGuard } from '@/lib/unsavedGuard';
import { toast } from '@/lib/toast';
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
 * change from "only Ctrl/⌘ + Enter", and it applies to the task discussion too. Brief 013 took
 * the helper line under the composer away (the client asked for it gone), with the size and
 * character counts it carried.
 *
 * ## A message is text, a file, or a voice note — and never two of the last two
 *
 * The composer gained a microphone. `VoiceRecorder` owns the whole gesture, the waveform, the
 * timer and the preview, and hands back a `VoiceClip` only once the reader has stopped and kept
 * it; nothing reaches the network until Send, which is the same Send as before. A recording and
 * a picked file are **mutually exclusive** — one endpoint field, one attachment per message —
 * so whichever is in hand disables the other control and says why rather than silently winning.
 *
 * On a browser with no `MediaRecorder`, or none of the four containers the endpoint accepts,
 * `VoiceRecorder` renders nothing at all and this composer is exactly what it was.
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
 * ## Read state — messaging polish (supersedes reliability slice 5's `X-HQ-Focused`)
 *
 * - **On screen** = the tab visible AND the window focused. The conversation is then left out of
 *   the top-bar icon, the sidebar pill, the rail pill and the page description
 *   (`setViewingConversation()` in `Realtime/shell.ts`).
 * - **Reading** = on screen AND scrolled to the newest message. Only then does a re-read send
 *   `read=1`; otherwise it sends `read=0` and nothing is marked. `catchUp()` posts
 *   `through=<newest drawn id>` when focus, visibility or a scroll to the bottom turns "not
 *   reading" into "reading" with something still unread.
 * - **The unread line** advances only while the reader has stayed at the bottom
 *   (`viewingStreak`): arrivals they watched get no line, arrivals while they were away keep it.
 *   Brief 009: "watched" is the tab visible and the list at the bottom when the re-read goes out
 *   AND when it lands — whatever triggered it (a poll, a socket ping, a post) — and such a re-read
 *   also marks read. A line already drawn stays where it is but does not count what was watched
 *   (`watchedIds`); sending a message of your own clears it.
 * - The list stays pinned to the bottom when a lazy image grows it (`@load.capture`).
 * - **The task discussion** (`routes.read === null`) keeps the old rule: every fetch reads.
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
        /** Opt-in composer placeholder. `''` draws none (the task detail, brief 016). */
        placeholder?: string | null;
        /**
         * Opt-in (brief 016): `footer` pins the composer to the bottom of the `DetailDrawer` it
         * sits in, as one compact row — icon-only Attach and Mentions, no placeholder.
         * Outside a drawer it falls back to `inline`, which is what every other mount is.
         */
        composerPlacement?: 'inline' | 'footer';
    }>(),
    { heading: null, description: null, scroll: false, placeholder: null, composerPlacement: 'inline' },
);

const emit = defineEmits<{
    settled: [];
    /** Messaging polish: this thread has just marked itself read, so the list's counts moved. */
    read: [];
}>();

const uid = useId();
const bodyId = `${uid}-body`;
const pickerId = `${uid}-file`;
const errorId = `${uid}-error`;
const progressId = `${uid}-progress`;

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

/**
 * Brief 009, resilience: a background re-read that fails while messages are on screen never
 * blanks the thread. It says "Reconnecting…" quietly above the composer and tries again after
 * 2 s, 5 s, 10 s, then every 30 s, until a read succeeds. Only the very first load (nothing on
 * screen yet) may show the error panel; a 404 is still the permanent answer it always was.
 */
const reconnecting = ref(false);
const RECONNECT_STEPS_MS = [2_000, 5_000, 10_000, 30_000] as const;
let reconnectStep = 0;
let reconnectTimer: ReturnType<typeof setTimeout> | null = null;

function clearReconnect(): void {
    if (reconnectTimer !== null) {
        clearTimeout(reconnectTimer);
        reconnectTimer = null;
    }
}

/** A failed read: blank the thread only when there is nothing to keep showing. */
function readFailed(message: string): void {
    if (thread.value.messages.length === 0) {
        loadError.value = message;

        return;
    }

    reconnecting.value = true;
    clearReconnect();

    const delay = RECONNECT_STEPS_MS[Math.min(reconnectStep, RECONNECT_STEPS_MS.length - 1)];

    reconnectStep += 1;
    reconnectTimer = setTimeout(() => {
        reconnectTimer = null;
        void load();
    }, delay);
}

function readSucceeded(): void {
    reconnecting.value = false;
    reconnectStep = 0;
    clearReconnect();
}

/** Bumped per fetch so a slow answer for a thread that has moved on cannot land in it. */
const token = ref(0);

const listEl = ref<HTMLElement | null>(null);
const bodyEl = ref<InstanceType<typeof Textarea> | null>(null);

/* ------------------------------------------------------------------ the composer */

const body = ref('');
const picked = ref<File | null>(null);
/**
 * Polish 005: more files waiting behind `picked`. One message still carries one attachment
 * (`StoreMessageRequest`), so several are sent Telegram-style as consecutive messages: the
 * typed text goes with the first, and each queued file follows on its own once the one before
 * it has landed.
 */
const queued = ref<File[]>([]);
/** At most this many files wait in the composer at once. */
const MAX_ATTACHMENTS = 10;
/** Everything in the composer, in send order. */
const attachments = computed<File[]>(() => (picked.value === null ? [] : [picked.value, ...queued.value]));
/** One object URL per image in the composer, revoked when the file leaves it. */
const previewUrls = new Map<File, string>();

function isImage(file: File): boolean {
    return file.type.startsWith('image/') && file.type !== 'image/svg+xml';
}

function previewFor(file: File): string | null {
    if (!isImage(file)) {
        return null;
    }

    let url = previewUrls.get(file);

    if (url === undefined) {
        url = URL.createObjectURL(file);
        previewUrls.set(file, url);
    }

    return url;
}

watch(attachments, (files) => {
    for (const [file, url] of previewUrls) {
        if (!files.includes(file)) {
            URL.revokeObjectURL(url);
            previewUrls.delete(file);
        }
    }
});
/** The finished recording, once the reader has stopped and kept it. `null` until then. */
const voiceClip = ref<VoiceClip | null>(null);
/** The mic is open, or a clip is sitting in the preview. Either way the paperclip is off. */
const voiceActive = ref(false);
const pickedError = ref<string | null>(null);
const serverError = ref<string | null>(null);
const posting = ref(false);
const pickerEl = ref<HTMLInputElement | null>(null);
/** Brief 013: the message being answered, quoted above the composer until it is sent or cancelled. */
const replyingTo = ref<ThreadMessage | null>(null);

/**
 * Reliability slice 2b. How far an attachment or a voice note has got (0-100) while it is
 * going, and — when it got no answer or a 5xx — which of the two is still sitting here waiting
 * for *Try again*. The composer is never reset on a failure, so the file or the clip is still
 * the one that goes. Said here and not also as a toast (brief 009: the send is a JSON request).
 */
const sendProgress = ref<number | null>(null);
const sendFailed = ref<'voice' | 'file' | null>(null);

const SEND_FAILED_TEXT = {
    voice: 'Voice note not sent — check your connection. It is still here; try again.',
    file: 'Upload failed — check your connection. Your file is still here; try again.',
} as const;

const mentions = useMentions(body);

const fieldError = computed(() => pickedError.value ?? serverError.value);

/**
 * One attachment per message, so the two ways of making one are exclusive.
 *
 * Each control stays on screen and says why it cannot be used — a paperclip that vanished when
 * you started recording would read as a bug, and `StoreMessageRequest` would refuse the pair
 * anyway. Recording in progress also holds Send: nothing is uploaded mid-recording.
 */
const micBlocked = computed(() => (picked.value === null ? null : 'Remove the attached file first'));
const attachBlocked = computed(() => voiceActive.value);
const stillRecording = computed(() => voiceActive.value && voiceClip.value === null);

/** The textarea grows with what is typed and then scrolls inside itself. */
const COMPOSER_MAX_PX = 160;
/** The pinned row's ceiling: about five lines, so the drawer body keeps most of the panel. */
const COMPOSER_PINNED_MAX_PX = 116;

/* ------------------------------------------------------------------ pinned composer (brief 016) */

const reportInset = inject(DRAWER_FOOTER_INSET, null);
/** Pinned only when asked for AND inside a drawer that can make room for it. */
const composerPinned = computed(() => props.composerPlacement === 'footer' && reportInset !== null);
const composerEl = ref<HTMLFormElement | null>(null);
let insetObserver: ResizeObserver | null = null;

watch(
    [composerEl, composerPinned],
    ([el, isPinned]) => {
        insetObserver?.disconnect();
        insetObserver = null;

        if (reportInset === null) {
            return;
        }

        if (!isPinned || el === null) {
            reportInset(0);

            return;
        }

        insetObserver = new ResizeObserver(() => reportInset(el.offsetHeight));
        insetObserver.observe(el);
        reportInset(el.offsetHeight);
    },
    { flush: 'post' },
);

onBeforeUnmount(() => {
    insetObserver?.disconnect();
    reportInset?.(0);
});

function field(): HTMLTextAreaElement | undefined {
    return bodyEl.value?.$el as HTMLTextAreaElement | undefined;
}

function grow(): void {
    const el = field();

    if (el === undefined) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, composerPinned.value ? COMPOSER_PINNED_MAX_PX : COMPOSER_MAX_PX)}px`;
}

function clearPicked(): void {
    picked.value = null;
    queued.value = [];
    pickedError.value = null;
    sendFailed.value = null;

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
    // `VoiceRecorder` watches both: it revokes the object URL and hands the microphone back.
    voiceClip.value = null;
    voiceActive.value = false;
    sendFailed.value = null;
    replyingTo.value = null;

    void nextTick(grow);
}

/* ------------------------------------------------------------------ the draft */

/**
 * Reliability slice 3: the half-typed message survives an F5 and a click elsewhere.
 *
 * Kept per signed-in person and per conversation in sessionStorage (`lib/drafts.ts` says why not
 * localStorage), saved ~400 ms after the last keystroke and flushed when the page is hidden, the
 * thread switches or this unmounts. Restored on mount and on a switch, with a quiet
 * "Draft restored." under the composer once. Cleared by a successful send and by emptying the
 * field. Text only — a picked file or a voice clip cannot survive a reload, which is what the
 * unsaved-changes guard below is for.
 */
const DRAFT_SAVE_MS = 400;

const page = usePage();
const userId = computed(() => (page.props.auth as { user?: { id?: number } | null } | undefined)?.user?.id ?? null);

function draftKeyFor(conversationId: number | null | undefined): string | null {
    return messageDraftKey(userId.value, conversationId);
}

/** The key the composer is writing to right now; moves with the thread. */
let draftKey: string | null = draftKeyFor(props.thread.conversation_id);
let draftTimer: ReturnType<typeof setTimeout> | null = null;
/** What was restored, so the cue goes away as soon as the text is somebody's again. */
let restoredText: string | null = null;
const draftRestored = ref(false);

function flushDraft(): void {
    if (draftTimer === null) {
        return;
    }

    clearTimeout(draftTimer);
    draftTimer = null;

    // Signed out, or moved surface (and so also during the other-user reload, which only runs
    // from an ended session): what is on screen is not written back for the next person.
    if (sessionState.status !== 'ok') {
        return;
    }

    writeDraft(draftKey, body.value);
}

function restoreDraft(): void {
    const text = readDraft(draftKey);

    restoredText = text === '' ? null : text;
    draftRestored.value = restoredText !== null;

    if (restoredText !== null) {
        body.value = restoredText;
        void nextTick(grow);
    }
}

watch(body, (text) => {
    if (restoredText !== null && text !== restoredText) {
        draftRestored.value = false;
        restoredText = null;
    }

    if (draftTimer !== null) {
        clearTimeout(draftTimer);
        draftTimer = null;
    }

    // Emptied: gone now, not in 400 ms — an F5 in between must not bring it back.
    if (text.trim() === '') {
        clearDraft(draftKey);

        return;
    }

    const key = draftKey;

    draftTimer = setTimeout(() => {
        draftTimer = null;

        if (sessionState.status === 'ok') {
            writeDraft(key, text);
        }
    }, DRAFT_SAVE_MS);
});

/** The page is going (F5, tab closed, sent to the background): keep what was typed. */
function onPageHide(): void {
    flushDraft();
}

/**
 * A picked file, a voice clip, or typed text asks before a navigation or an F5 throws it away.
 * The text is also drafted, but a reload still costs the file or the clip, so the guard asks.
 */
useUnsavedGuard(() => picked.value !== null || voiceClip.value !== null || voiceActive.value || body.value.trim() !== '');

function choose(event: Event): void {
    const input = event.target as HTMLInputElement;

    for (const file of Array.from(input.files ?? [])) {
        adoptFile(file);
    }

    // So the same file can be chosen again after it is removed.
    input.value = '';
}

/**
 * One way in for a file, whether it was picked, pasted or dropped: same check, same preview.
 * Polish 005: a second file no longer replaces the first — it joins the queue behind it.
 */
function adoptFile(file: File | null): void {
    serverError.value = null;
    sendFailed.value = null;

    if (file === null) {
        clearPicked();

        return;
    }

    const rejection = messageFileRejection(file);

    if (picked.value === null) {
        picked.value = file;
        pickedError.value = rejection;

        return;
    }

    if (rejection !== null) {
        // The files already here stay; only this one is refused, and it says why.
        toast.error(`${file.name}: ${rejection}`);

        return;
    }

    if (attachments.value.length >= MAX_ATTACHMENTS) {
        toast.error(`Up to ${MAX_ATTACHMENTS} files at a time. Send these first.`);

        return;
    }

    queued.value = [...queued.value, file];
}

/** Take one file out of the composer (polish 005). */
function removeAttachment(index: number): void {
    if (index === 0) {
        const [next, ...rest] = queued.value;

        picked.value = next ?? null;
        queued.value = rest;
        pickedError.value = next === undefined ? null : messageFileRejection(next);
        sendFailed.value = null;

        if (pickerEl.value) {
            pickerEl.value.value = '';
        }

        return;
    }

    queued.value = queued.value.filter((_, at) => at !== index - 1);
}

/** Can a file be taken in right now — by paste or by drop? The paperclip's own conditions. */
const canAdopt = computed(() => thread.value.can_post && !posting.value && !attachBlocked.value);

/**
 * Brief 013: Ctrl/⌘ + V of an image attaches it, the way Telegram does. Bound once, on the
 * composer FORM in the capturing phase, so both composer variants share it whichever control in
 * the form has focus. The image comes from `clipboardData.items` (what a screenshot or a copied
 * picture puts there), else `clipboardData.files[0]`; it is renamed to when it was pasted and
 * goes through `adoptFile()`, so it shows the same preview a picked file does. A text-only
 * clipboard is left entirely alone — no `preventDefault()`, so the text lands in the field.
 */
function onPaste(event: ClipboardEvent): void {
    if (!canAdopt.value) {
        return;
    }

    const image = clipboardImage(event.clipboardData);

    if (image === null) {
        return;
    }

    event.preventDefault();

    const at = new Date();
    const type = image.type !== '' ? image.type : 'image/png';

    adoptFile(new File([image], pastedFileName(type, at), { type, lastModified: at.getTime() }));
}

/**
 * A paste anywhere else in the thread panel — the list, a button, the panel itself. One inside
 * the form was already handled by the form's own listener above, so it is not read twice.
 */
function onPanelPaste(event: ClipboardEvent): void {
    const target = event.target as Node | null;

    if (event.defaultPrevented || (target !== null && composerEl.value?.contains(target))) {
        return;
    }

    onPaste(event);
}

/* ------------------------------------------------------------------ drag and drop (brief 013) */

/** The "Drop to attach" overlay is up. */
const dragging = ref(false);
/** `dragenter`/`dragleave` fire for every child crossed; the depth keeps the overlay steady. */
let dragDepth = 0;

function carriesFiles(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

function onDragEnter(event: DragEvent): void {
    if (!carriesFiles(event) || !canAdopt.value) {
        return;
    }

    event.preventDefault();
    dragDepth += 1;
    dragging.value = true;
}

function onDragOver(event: DragEvent): void {
    if (!carriesFiles(event) || !canAdopt.value) {
        return;
    }

    event.preventDefault();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'copy';
    }

    dragging.value = true;
}

function onDragLeave(event: DragEvent): void {
    if (!dragging.value || !carriesFiles(event)) {
        return;
    }

    dragDepth = Math.max(0, dragDepth - 1);

    if (dragDepth === 0) {
        dragging.value = false;
    }
}

function onDrop(event: DragEvent): void {
    dragDepth = 0;
    dragging.value = false;

    if (!carriesFiles(event) || !canAdopt.value) {
        return;
    }

    event.preventDefault();

    for (const file of Array.from(event.dataTransfer?.files ?? [])) {
        adoptFile(file);
    }
}

// A clip discarded or re-recorded is not the one that failed.
watch(voiceClip, () => {
    if (sendFailed.value === 'voice') {
        sendFailed.value = null;
    }
});

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

/** An emoji was picked: insert it at the caret (as `mention()` does) and give the caret back. */
function insertEmoji(emoji: string): void {
    const el = field();
    const caret = el?.selectionStart ?? body.value.length;
    const end = el?.selectionEnd ?? caret;

    body.value = `${body.value.slice(0, caret)}${emoji}${body.value.slice(end)}`;
    rememberEmoji(emoji);

    const at = caret + emoji.length;

    void nextTick(() => {
        el?.focus();
        el?.setSelectionRange?.(at, at);
        grow();
    });
}

/* ------------------------------------------------------------------ replying (brief 013) */

/** The viewer's name, for quoting their own message before the server has. */
const viewerName = computed(
    () => (page.props.auth as { user?: { name?: string } | null } | undefined)?.user?.name ?? null,
);

/** The composer's quote: the message being answered, in the shape the server will send back. */
const replyQuote = computed<ThreadReplyTo | null>(() =>
    replyingTo.value === null ? null : replyReference(replyingTo.value, viewerName.value),
);

/** Reply was chosen on a message: quote it above the composer and put the caret in the field. */
function startReply(message: ThreadMessage): void {
    if (!thread.value.can_post || message.id < 1 || message.is_deleted) {
        return;
    }

    replyingTo.value = message;

    void nextTick(() => {
        const el = field();

        el?.focus();
        el?.setSelectionRange?.(body.value.length, body.value.length);
    });
}

function cancelReply(): void {
    replyingTo.value = null;
    void nextTick(() => field()?.focus());
}

/** Esc in an empty composer drops the reply; with text in it, Esc does nothing here. */
function onComposerEscape(event: KeyboardEvent): void {
    if (replyingTo.value === null || body.value !== '') {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    cancelReply();
}

/** The message a quote points at was highlighted, for ~1.5 s. */
const highlightedId = ref<number | null>(null);
let highlightTimer: ReturnType<typeof setTimeout> | null = null;
/** How many older pages a jump may load looking for the original. */
const JUMP_MAX_PAGES = 5;
let jumping = false;

function rowFor(id: number): HTMLElement | null {
    return listEl.value?.querySelector<HTMLElement>(`[data-message-id="${id}"]`) ?? null;
}

/**
 * A quote was clicked: scroll its original into view and highlight it. Not loaded yet, so
 * "Load earlier messages" is pressed for the reader, up to five pages; still not there, a toast
 * says so.
 */
async function jumpTo(id: number): Promise<void> {
    if (jumping) {
        return;
    }

    jumping = true;

    try {
        let row = rowFor(id);
        let pages = 0;

        while (row === null && pages < JUMP_MAX_PAGES && thread.value.has_more) {
            const oldest = thread.value.messages.find((message) => message.id > 0)?.id ?? null;

            if (oldest === null) {
                break;
            }

            pages += 1;
            await load(oldest);
            await nextTick();
            row = rowFor(id);
        }

        if (row === null) {
            toast.info('That message is too far back.');

            return;
        }

        pinned = false;
        viewingStreak = false;
        row.scrollIntoView({ block: 'center', behavior: 'smooth' });

        if (highlightTimer !== null) {
            clearTimeout(highlightTimer);
        }

        highlightedId.value = id;
        highlightTimer = setTimeout(() => {
            highlightedId.value = null;
            highlightTimer = null;
        }, 1500);
    } finally {
        jumping = false;
    }
}

onBeforeUnmount(() => {
    if (highlightTimer !== null) {
        clearTimeout(highlightTimer);
    }
});

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

/** Where this reader's line sat when the thread opened. Deliberately old: reading marks read. */
const anchor = ref<number | null>(parseAt(props.thread.last_read_at));

/**
 * Messaging polish: move the line past everything drawn — only when the reader watched it land.
 * A line already on screen (arrivals from while they were away) is left where it is.
 */
function advanceAnchor(messages: ThreadMessage[]): void {
    const newest = messages.reduce<number | null>((top, message) => {
        const at = parseAt(message.created_at);

        return at !== null && (top === null || at > top) ? at : top;
    }, null);

    if (newest !== null && (anchor.value === null || newest > anchor.value)) {
        anchor.value = newest;
    }
}

/** Arrivals the reader watched land while a line was already drawn: never counted as new. */
const watchedIds = ref(new Set<number>());

function isUnread(message: ThreadMessage): boolean {
    if (message.is_mine || message.id < 0 || watchedIds.value.has(message.id)) {
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

/**
 * Is the list at its newest message? Tracked on scroll, so a lazy image that grows the list
 * after a message was drawn can put the reader back where they were (`onMediaLoad`).
 */
let pinned = true;

function scrollToBottom(): void {
    const el = listEl.value;

    if (el !== null) {
        el.scrollTop = el.scrollHeight;
    }
}

/** A lazy image or a voice note finished loading and the list grew under the reader. */
function onMediaLoad(): void {
    if (pinned) {
        scrollToBottom();
        // A picture swaps its shimmering placeholder for its real size in the render AFTER its
        // `load` event (this listener runs first, in the capture phase), so settle once more
        // when that has painted.
        requestAnimationFrame(() => {
            if (pinned) {
                scrollToBottom();
            }
        });
    }
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

        pinned = true;
    });
}

/* ------------------------------------------------------------ on screen, and reading */

/** Task discussion keeps "every fetch reads"; everything under `/messages` tracks reading. */
const tracksReading = computed(() => props.routes.read !== null);

/** The tab is visible and the window has focus. */
function onScreen(): boolean {
    return windowFocused();
}

/** On screen, and at the newest message. */
function reading(): boolean {
    return onScreen() && atBottom();
}

/** Brief 009: the tab is visible and the list is at the newest message — what arrives is seen. */
function visibleAtBottom(): boolean {
    return (typeof document === 'undefined' || document.visibilityState === 'visible') && atBottom();
}

/**
 * Has the reader stayed at the bottom, on screen, since the last re-read? Broken by a blur, a
 * hidden tab or a scroll up; the next re-read starts it again.
 */
let viewingStreak = false;

/** Tell the shell whether this conversation is the one on screen. */
function syncViewing(): void {
    if (!tracksReading.value) {
        return;
    }

    const id = thread.value.conversation_id;

    if (onScreen() && !threadGone.value) {
        setViewingConversation(id);
    } else if (isViewingConversation(id)) {
        setViewingConversation(null);
    }
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

let catchingUp = false;

/**
 * Mark read through the newest message on screen — the reader has just come back to it (focus,
 * visibility) or scrolled down to it, and a background re-read left something unread. Never
 * past what is drawn: a message that lands a moment later stays unread until it is seen.
 */
async function catchUp(): Promise<void> {
    const route = props.routes.read;

    if (route === null || catchingUp || posting.value || threadGone.value || !isSessionLive()) {
        return;
    }

    if (!reading() || thread.value.unread_count < 1) {
        return;
    }

    const through = highestId(thread.value.messages);
    const conversationId = thread.value.conversation_id;

    if (through < 1) {
        return;
    }

    catchingUp = true;

    try {
        const response = await fetchWithTimeout(route, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ through }),
        });

        if (!response.ok) {
            // 401 / 419 / a surface 403 is the session module's; anything else waits for the
            // next re-read, which will say what is still unread.
            await reportResponse(response);

            return;
        }

        if (thread.value.conversation_id === conversationId) {
            thread.value = { ...thread.value, unread_count: 0 };
        }

        adoptConversationUnread(conversationId, 0);
        emit('read');
    } catch {
        // A dropped connection: the next re-read or return of focus tries again.
    } finally {
        catchingUp = false;
    }
}

/** The list was scrolled: at the bottom (and on screen) is reading; anywhere else breaks it. */
function onScroll(): void {
    pinned = atBottom();

    if (!pinned) {
        viewingStreak = false;

        return;
    }

    void catchUp();
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

    // Local (negative-id) rows are not history; `withLocal()` puts them back at the end.
    const earlier = current.messages.filter((message) => message.id > 0 && message.id < oldest);

    if (earlier.length === 0) {
        return payload;
    }

    return { ...payload, messages: [...earlier, ...payload.messages], has_more: current.has_more };
}

/**
 * Brief 009, no flashing: a re-read is MERGED into what is drawn (`mergeThreadMessages`) — an
 * unchanged row keeps its object and an attachment keeps its first signed url — then the loaded
 * history is kept in front of it and this composer's own unsent rows stay at the end.
 */
function mergeRead(current: ThreadPayload, payload: ThreadPayload): ThreadPayload {
    const real = current.messages.filter((message) => message.id > 0);
    const merged = mergeThreadMessages(real, payload.messages, Date.now() + EXPIRY_MARGIN_MS);
    const next = keepLoadedHistory(current, { ...payload, messages: merged });

    return withLocal(current, next);
}

/**
 * Brief 010: an edit, a delete or a reaction answered with the server's `message` (or a row's
 * optimistic version of it). It goes through the same merge a re-read uses, so the row's
 * attachments keep the objects — and the signed urls — they were first drawn with.
 */
function replaceMessage(message: ThreadMessage): void {
    const real = thread.value.messages.filter((item) => item.id > 0);

    if (!real.some((item) => item.id === message.id)) {
        return;
    }

    const merged = mergeThreadMessages(
        real,
        real.map((item) => (item.id === message.id ? message : item)),
        Date.now() + EXPIRY_MARGIN_MS,
    );

    thread.value = withLocal(thread.value, { ...thread.value, messages: merged });
}

/** Optimistic rows still waiting (or failed) ride along under every re-read. */
function withLocal(current: ThreadPayload, next: ThreadPayload): ThreadPayload {
    const local = current.messages.filter((message) => message.id < 0);

    if (local.length === 0) {
        return next;
    }

    return { ...next, messages: [...next.messages, ...local] };
}

async function load(before: number | null = null): Promise<void> {
    // Signed out, or moved to another surface: no read goes out, and the thread keeps what it
    // shows. The session dialog is the one thing that says so.
    if (!isSessionLive()) {
        return;
    }

    const mine = ++token.value;
    const wasAtBottom = before === null && atBottom();
    const sentAt = Date.now();

    // Messaging polish: read once, so the flag sent and the bookkeeping below agree. `?before=`
    // never marks on the server; the task discussion sends no flag and every fetch reads.
    const markRead = before === null && tracksReading.value && (reading() || visibleAtBottom());
    // Visible and at the bottom when this went out — what arrives now, they are watching land,
    // whether a poll, a socket ping or a post asked for it (brief 009).
    const watched = before === null && (viewingStreak || visibleAtBottom());
    const hadUnread = thread.value.unread_count > 0;

    refreshing.value = true;

    try {
        let url = props.routes.thread;

        if (before !== null) {
            url = `${url}?before=${before}`;
        } else if (tracksReading.value) {
            url = `${url}?read=${markRead ? 1 : 0}`;
        }

        const response = await fetchWithTimeout(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (mine !== token.value) {
            return;
        }

        if (!response.ok) {
            // The session ended, or the role changed: not this thread's failure to report.
            if (await reportResponse(response)) {
                return;
            }

            // 404 is "you may not see this conversation", said the way the backend says it
            // everywhere. There is no 403 to tell it apart from.
            if (response.status === 404) {
                loadError.value = 'This conversation is not available.';
                readSucceeded();
            } else {
                readFailed('The conversation could not be loaded.');
            }

            // A refusal is not a transient failure, so the live refresh stops asking. Anything
            // else — a dropped connection, a 500 — is worth the next tick.
            threadGone.value = response.status === 404;

            if (before === null && response.status >= 500) {
                threadGate.fail(sentAt);
            }

            return;
        }

        if (before === null) {
            threadGate.succeed();
        }

        const payload = (await response.json()) as ThreadPayload;

        if (mine !== token.value) {
            return;
        }

        if (before === null) {
            const arrivals = payload.messages.filter((message) => message.id > seen && !message.is_mine);
            const freshFromOthers = arrivals.length > 0;
            // Arrivals the reader watched get no unread line; a line already drawn stays, but
            // does not count them.
            const sawThem = watched && visibleAtBottom();
            const advance = sawThem && firstUnreadId.value === null;

            if (sawThem && !advance && arrivals.length > 0) {
                const next = new Set(watchedIds.value);

                arrivals.forEach((message) => next.add(message.id));
                watchedIds.value = next;
            }

            announce(payload.messages);
            thread.value = mergeRead(thread.value, payload);
            keepAtBottom(wasAtBottom);

            if (advance) {
                advanceAnchor(payload.messages);
            }

            viewingStreak = reading();

            if (tracksReading.value) {
                adoptConversationUnread(payload.conversation_id, payload.unread_count);

                // Marked something the list still counts: let it re-read its numbers.
                if (markRead && (hadUnread || freshFromOthers)) {
                    emit('read');
                }
            }
        } else {
            // Older history, prepended. `seen` is untouched: nothing here is new.
            thread.value = {
                ...payload,
                messages: [...payload.messages, ...thread.value.messages],
                has_more: payload.has_more,
            };
        }

        loadError.value = null;
        readSucceeded();
    } catch {
        if (before === null) {
            threadGate.fail(sentAt);
        }

        if (mine === token.value) {
            readFailed('The conversation could not be loaded.');
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

/**
 * Older history, one page (`THREAD_WINDOW`, 30) at a time, as the reader scrolls up
 * (2026-10-04, the client: "if the user scroll for old message … it will load again some data,
 * again some data like this"). The top of the log carries a sentinel; when it comes within
 * `OLDER_MARGIN_PX` of view the next page is fetched, shimmering rows hold its place, and the
 * reader stays on the message they were reading — the distance from the BOTTOM of the list is
 * what is kept, so the page that lands above them pushes nothing they are looking at.
 *
 * The button is still there for a keyboard or a screen reader, visible when focused.
 */
const loadingOlder = ref(false);
const olderSentinel = ref<HTMLElement | null>(null);
const olderInView = ref(false);
const OLDER_MARGIN_PX = 400;
let olderObserver: IntersectionObserver | null = null;

async function loadEarlier(): Promise<void> {
    const oldest = thread.value.messages.find((message) => message.id > 0)?.id ?? null;

    if (oldest === null || loadingOlder.value || jumping || !thread.value.has_more) {
        return;
    }

    loadingOlder.value = true;

    const el = listEl.value;
    const fromBottom = el === null ? 0 : el.scrollHeight - el.scrollTop;

    try {
        await load(oldest);
        await nextTick();

        const landed = (thread.value.messages.find((message) => message.id > 0)?.id ?? null) !== oldest;

        if (landed && el !== null && props.scroll) {
            // Keep the reader's place: the same distance from the bottom as before the page landed.
            el.scrollTop = el.scrollHeight - fromBottom;
        }

        // The observer reports a frame late, so ask the geometry now: a page that leaves the top
        // still in reach asks for the next one, a page that filled the screen does not. A failed
        // or overtaken read does not retry by itself — scrolling away and back, or the button.
        olderInView.value = landed && olderNear();
    } finally {
        loadingOlder.value = false;
    }
}

/** Is the top sentinel within `OLDER_MARGIN_PX` of the visible part of the log? */
function olderNear(): boolean {
    const root = listEl.value;
    const sentinel = olderSentinel.value;

    if (root === null || sentinel === null) {
        return false;
    }

    return sentinel.getBoundingClientRect().bottom >= root.getBoundingClientRect().top - OLDER_MARGIN_PX;
}

function watchForOlder(): void {
    olderObserver?.disconnect();
    olderObserver = null;

    // Only a thread that scrolls itself (the Messages page). In the project Discussion tab and
    // the task panel the page scrolls, the sentinel never leaves the screen, and watching it
    // would pull in the whole history; there the button stays, visible.
    if (!props.scroll || olderSentinel.value === null || typeof IntersectionObserver === 'undefined') {
        return;
    }

    olderObserver = new IntersectionObserver(
        (entries) => {
            olderInView.value = entries.some((entry) => entry.isIntersecting);
        },
        { root: listEl.value, rootMargin: `${OLDER_MARGIN_PX}px 0px 0px 0px` },
    );
    olderObserver.observe(olderSentinel.value);
}

// The sentinel comes and goes with `has_more`; observe whichever element is there now.
watch(olderSentinel, () => watchForOlder());

// In view, nothing loading, more to load: fetch. A page that lands and still leaves the top in
// view (a short page, a tall screen) asks again, until the screen is full or history ends.
watch([olderInView, loadingOlder, () => thread.value.has_more], ([inView, busy, more]) => {
    if (inView && !busy && more) {
        void loadEarlier();
    }
});

onBeforeUnmount(() => {
    olderObserver?.disconnect();
});

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
/**
 * Reliability slice 2a: the thread used to retry every ten seconds for ever against a server
 * that was not answering. Healthy, every tick reads; after a failed read the next waits twice as
 * long, up to five minutes, and the browser coming back online reads at once (`useLiveRefresh`
 * pings on reconnect, and the gate opens on the same event).
 */
const threadGate = backoff(THREAD_POLL_MS);

const live = useLiveRefresh(
    () => conversationChannel(thread.value.conversation_id),
    () => {
        if (threadGate.ready()) {
            void load();
        }
    },
    {
        intervalMs: THREAD_POLL_MS,
        canRefresh: () => !posting.value && !threadGone.value,
    },
);

watch(
    () => [props.thread.conversation_id, props.thread] as const,
    ([id, payload], [previousId]) => {
        if (id !== previousId) {
            anchor.value = parseAt(payload.last_read_at);
            watchedIds.value = new Set();
            seen = highestId(payload.messages);
            announcement.value = '';
            actionStatus.value = '';
            loadError.value = null;
            readSucceeded();
            // The old thread keeps its draft; the new one brings its own back.
            flushDraft();
            resetComposer();
            draftKey = draftKeyFor(id);
            restoreDraft();
            thread.value = payload;

            void load();

            return;
        }

        const wasAtBottom = atBottom();

        announce(payload.messages);
        thread.value = mergeRead(thread.value, payload);
        keepAtBottom(wasAtBottom);
    },
);

// A thread switch (or a refusal) changes which conversation is on screen.
watch(
    () => [thread.value.conversation_id, threadGone.value] as const,
    ([, gone], [previousId]) => {
        if (gone || previousId !== thread.value.conversation_id) {
            if (isViewingConversation(previousId)) {
                setViewingConversation(null);
            }

            viewingStreak = false;
        }

        syncViewing();
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

/**
 * Focus, blur and visibility: say whether this conversation is on screen, and — coming back to
 * it at the bottom with something a background re-read left unread — catch up (`catchUp()`).
 *
 * Wired to window `focus`/`blur` AND `visibilitychange`: browsers disagree about which fires
 * first when a tab comes back, and `focus` can arrive while the page still reports hidden.
 * `catchingUp` and `unread_count` make the pair cost one request at most.
 */
function onAttentionChange(): void {
    syncViewing();

    if (!onScreen()) {
        viewingStreak = false;

        return;
    }

    void catchUp();
}

onMounted(() => {
    // To the bottom FIRST: whether the opening read marks the thread depends on the reader being
    // at the newest message (`reading()`). `anchor` was taken before it, which keeps the line on
    // screen.
    scrollToBottom();
    pinned = true;
    syncViewing();

    void load();

    keepAtBottom(true);
    grow();

    ticker = setInterval(refreshIfStale, TICK_MS);
    document.addEventListener('visibilitychange', refreshIfStale);
    window.addEventListener('focus', onAttentionChange);
    window.addEventListener('blur', onAttentionChange);
    document.addEventListener('visibilitychange', onAttentionChange);

    restoreDraft();
    window.addEventListener('pagehide', onPageHide);
});

onBeforeUnmount(() => {
    // What this thread looked like when the reader left it: the next opening previews it.
    rememberThread(thread.value);
    clearReconnect();
    clearInterval(ticker);
    document.removeEventListener('visibilitychange', refreshIfStale);
    window.removeEventListener('focus', onAttentionChange);
    window.removeEventListener('blur', onAttentionChange);
    document.removeEventListener('visibilitychange', onAttentionChange);

    if (tracksReading.value && isViewingConversation(thread.value.conversation_id)) {
        setViewingConversation(null);
    }

    flushDraft();
    window.removeEventListener('pagehide', onPageHide);
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

    // Enter is still a send, and it must not send half a sentence out from under a recording
    // that is still running. Stop it first; the clip is then a press away.
    if (stillRecording.value) {
        return;
    }

    const file = picked.value;
    const clip = voiceClip.value;
    const carrying: Outgoing['carrying'] = clip !== null ? 'voice' : file !== null ? 'file' : null;
    const text = body.value;
    const replyTo = replyingTo.value;

    // Nothing at all: let the server say its own sentence, as before, but without a ghost row.
    if (carrying === null && text.trim() === '') {
        void send({ tempId: null, text, people: [...mentions.picked.value], named: mentions.ids(), carrying, file, clip, replyTo });

        return;
    }

    const outgoing: Outgoing = {
        tempId: --tempSeq,
        text,
        people: [...mentions.picked.value],
        named: mentions.ids(),
        carrying,
        file,
        clip,
        replyTo,
    };

    appendLocal(optimistic(outgoing));

    if (carrying === null) {
        // Text only: the composer is free at once (Telegram), and the draft goes with it.
        dropDraft();
        resetComposer();
    }

    void send(outgoing);
}

/** One message on its way: what was typed, who it names, and the bytes if it carries any. */
interface Outgoing {
    /** The optimistic row's id (negative), or `null` for a send that draws none. */
    tempId: number | null;
    text: string;
    people: MessagePerson[];
    named: number[];
    carrying: 'voice' | 'file' | null;
    file: File | null;
    clip: VoiceClip | null;
    /** Brief 013: the message this one answers (`reply_to_id`), or `null`. */
    replyTo: ThreadMessage | null;
}

/** Negative, so a local row can never collide with a server id. */
let tempSeq = 0;
/** Sends waiting for an answer, by temp id — a failed one stays here for "tap to retry". */
const outbox = new Map<number, Outgoing>();
/** A text-only send gives up after this long and offers a retry; an upload is never timed out. */
const TEXT_SEND_TIMEOUT_MS = 30_000;

function optimistic(outgoing: Outgoing): ThreadMessage {
    return {
        id: outgoing.tempId ?? 0,
        body: outgoing.text.trim() === '' ? null : outgoing.text,
        author: userId.value === null ? null : { id: userId.value, name: null },
        is_mine: true,
        created_at: new Date().toISOString(),
        attachments: [],
        mentions: outgoing.people.filter((person) => outgoing.named.includes(person.id)),
        mentions_me: false,
        edited_at: null,
        is_deleted: false,
        can_edit: false,
        can_delete: false,
        reactions: [],
        seen: null,
        reply_to: outgoing.replyTo === null ? null : replyReference(outgoing.replyTo, viewerName.value),
        pending: true,
    };
}

function appendLocal(message: ThreadMessage): void {
    thread.value = { ...thread.value, messages: [...thread.value.messages, message] };

    void nextTick(() => {
        scrollToBottom();
        pinned = true;
    });
}

function patchLocal(tempId: number, patch: Partial<ThreadMessage> | null): void {
    const messages = thread.value.messages;
    const index = messages.findIndex((message) => message.id === tempId);

    if (index === -1) {
        return;
    }

    const next = [...messages];

    if (patch === null) {
        next.splice(index, 1);
    } else {
        next[index] = { ...messages[index], ...patch };
    }

    thread.value = { ...thread.value, messages: next };
}

/** The server's row takes the optimistic one's place, ordered by id among the real ones. */
function settleLocal(tempId: number, message: ThreadMessage): void {
    const rest = thread.value.messages.filter((item) => item.id !== tempId && item.id !== message.id);
    const real = [...rest.filter((item) => item.id > 0), message].sort((a, b) => a.id - b.id);
    const local = rest.filter((item) => item.id < 0);

    thread.value = { ...thread.value, messages: [...real, ...local] };
    seen = Math.max(seen, message.id);
}

function dropDraft(): void {
    if (draftTimer !== null) {
        clearTimeout(draftTimer);
        draftTimer = null;
    }

    clearDraft(draftKey);
}

/** "Not sent — tap to retry" on a row: the same message, sent again. */
function retry(tempId: number): void {
    const outgoing = outbox.get(tempId);

    if (outgoing === undefined) {
        return;
    }

    patchLocal(tempId, { failed: false, pending: true });
    void send(outgoing);
}

async function send(outgoing: Outgoing): Promise<void> {
    const { tempId, carrying, file, clip } = outgoing;
    const conversationId = thread.value.conversation_id;
    const data = new FormData();

    data.append('body', outgoing.text);
    outgoing.named.forEach((id, index) => data.append(`mentions[${index}]`, String(id)));

    if (outgoing.replyTo !== null) {
        data.append('reply_to_id', String(outgoing.replyTo.id));
    }

    // A recording wins only because the two cannot both exist: `micBlocked` and `attachBlocked`
    // are what make that true, and this order is the safety net rather than the rule.
    if (clip !== null) {
        // The filename carries the extension that matches the blob's own MIME type — the
        // server validates the pair, so `voice.webm` holding `audio/mp4` is a 422.
        data.append('file', clipFile(clip));
        data.append('kind', 'voice');
        data.append('duration', String(clip.seconds));
    } else if (file !== null) {
        data.append('file', file);
    }

    if (tempId !== null) {
        outbox.set(tempId, outgoing);
    }

    serverError.value = null;

    if (carrying !== null) {
        // An upload holds the composer, as before: the file or clip stays until it lands.
        posting.value = true;
        sendFailed.value = null;
        sendProgress.value = 0;
    }

    const stillHere = (): boolean => thread.value.conversation_id === conversationId;

    try {
        const result = await sendMessage(
            props.routes.store,
            data,
            csrfToken(),
            carrying === null ? undefined : (percent) => {
                sendProgress.value = percent;
            },
            carrying === null ? TEXT_SEND_TIMEOUT_MS : 0,
        );

        if (result.status >= 500) {
            reportNetworkFailure();
        } else {
            reportNetworkSuccess();
        }

        if (result.status === 201 || (result.status >= 200 && result.status < 300)) {
            if (tempId !== null) {
                outbox.delete(tempId);
            }

            const accepted = (result.json as { message?: ThreadMessage } | null)?.message;

            if (stillHere() && tempId !== null) {
                if (accepted !== undefined) {
                    settleLocal(tempId, accepted);
                } else {
                    patchLocal(tempId, null);
                    void load();
                }
            }

            playMessageSent();

            // Replying is reading: the "new messages" line has done its job.
            if (stillHere()) {
                advanceAnchor(thread.value.messages.filter((message) => message.id > 0));
            }

            if (carrying !== null) {
                // Polish 005: files still waiting go next, each as its own message.
                const rest = carrying === 'file' && stillHere() ? [...queued.value] : [];

                // Sent: the draft goes with it, before anything else can read it back.
                dropDraft();
                resetComposer();

                if (rest.length > 0) {
                    const [next, ...after] = rest;

                    picked.value = next;
                    queued.value = after;
                    pickedError.value = messageFileRejection(next);
                    posting.value = false;
                    void nextTick(post);
                }
            }

            emit('settled');

            return;
        }

        if (result.status >= 500 && carrying === null && tempId !== null) {
            // The server broke, not the message: it stays as a failed row, a tap from a retry.
            patchLocal(tempId, { pending: false, failed: true });

            return;
        }

        if (tempId !== null) {
            outbox.delete(tempId);
            patchLocal(tempId, null);
        }

        // The session ended, or the role changed: the session module says so.
        if (await reportResponse(new Response(JSON.stringify(result.json ?? {}), { status: result.status }))) {
            restoreText(outgoing);

            return;
        }

        if (result.status === 413) {
            serverError.value = TOO_LARGE_TEXT;
        } else if (result.status === 422 && replyRefusal(result.json) !== null) {
            // The original went (deleted, or not in this conversation): say so, drop the quote.
            serverError.value = replyRefusal(result.json);
            outgoing.replyTo = null;
            replyingTo.value = null;
        } else if (result.status === 422) {
            serverError.value = refusalText(result.json);
        } else if (result.status >= 500 && carrying !== null) {
            sendFailed.value = carrying;
        } else {
            serverError.value = refusalText(result.json);
        }

        restoreText(outgoing);
    } catch {
        reportNetworkFailure();

        if (carrying !== null) {
            // The composer still holds the bytes and says so, with its own Try again.
            if (tempId !== null) {
                outbox.delete(tempId);
                patchLocal(tempId, null);
            }

            sendFailed.value = carrying;
        } else if (tempId !== null) {
            patchLocal(tempId, { pending: false, failed: true });
        }
    } finally {
        if (carrying !== null) {
            posting.value = false;
            sendProgress.value = null;
        }
    }
}

/** The 422 sentence about `reply_to_id`, when that is what was refused. */
function replyRefusal(json: unknown): string | null {
    const value = (json as { errors?: Record<string, unknown> } | null)?.errors?.reply_to_id;
    const text = Array.isArray(value) ? value[0] : value;

    return typeof text === 'string' && text !== '' ? text : null;
}

/** A refused text-only send: its words, who it named and what it answered go back into an empty composer. */
function restoreText(outgoing: Outgoing): void {
    if (outgoing.carrying !== null || body.value.trim() !== '' || outgoing.text.trim() === '') {
        return;
    }

    body.value = outgoing.text;
    replyingTo.value = outgoing.replyTo;
    outgoing.people.forEach((person) => {
        if (!mentions.picked.value.some((held) => held.id === person.id)) {
            mentions.picked.value = [...mentions.picked.value, person];
        }
    });
    void nextTick(grow);
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
                // The drop overlay is placed against this panel — except when the composer is
                // pinned, which positions itself against the drawer and must keep doing so.
                !composerPinned && 'relative',
            )
        "
        data-testid="message-thread"
        @paste="onPanelPaste"
        @dragenter="onDragEnter"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
    >
        <!-- Brief 013: a file dragged over the thread. Nothing at all where it cannot be taken. -->
        <div
            v-if="dragging"
            class="pointer-events-none absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-primary bg-background/80 text-sm font-medium text-foreground"
            data-testid="drop-overlay"
            aria-hidden="true"
        >
            <ImagePlus class="size-8 text-primary" aria-hidden="true" />
            Drop to attach
        </div>

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
                <LiveIndicator :transport="live.transport.value" :interval-ms="THREAD_POLL_MS" />

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
                        'min-w-0 rounded-md focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none',
                        scroll && 'min-h-0 flex-1 overflow-y-auto pr-1',
                        layout === 'sided' && 'chat-wallpaper px-2 py-2',
                    )
                "
                @scroll.passive="onScroll"
                @load.capture="onMediaLoad"
                @loadedmetadata.capture="onMediaLoad"
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
                    <li
                        v-if="thread.has_more"
                        ref="olderSentinel"
                        class="flex min-w-0 flex-col gap-3 pb-2"
                        data-testid="older-sentinel"
                    >
                        <!-- Scrolling up loads the next page by itself; this is the keyboard's way in. -->
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :class="['self-center', scroll && 'sr-only focus-visible:not-sr-only']"
                            :aria-busy="loadingOlder || undefined"
                            @click="loadEarlier"
                        >
                            <ChevronUp aria-hidden="true" />
                            Load earlier messages
                        </Button>
                        <template v-if="loadingOlder">
                            <p class="sr-only" role="status">Loading earlier messages…</p>
                            <div
                                v-for="(width, index) in ['w-44', 'w-56', 'w-36']"
                                :key="index"
                                :class="['flex min-w-0 items-end gap-2', index === 2 ? 'justify-end' : 'justify-start']"
                                aria-hidden="true"
                            >
                                <span v-if="index !== 2" class="shimmer size-8 shrink-0 rounded-full" />
                                <span :class="['shimmer h-11 max-w-[75%] rounded-2xl', width]" />
                            </div>
                        </template>
                    </li>

                    <template v-for="entry in rendered" :key="entry.message.id">
                        <!-- The day pill (Telegram reference). A word, so it reads the same without colour. -->
                        <li
                            v-if="entry.dayLabel"
                            class="sticky top-1 z-10 flex min-w-0 justify-center py-2"
                        >
                            <span class="rounded-full border bg-card/90 px-3 py-0.5 text-xs font-medium text-muted-foreground backdrop-blur-sm">
                                {{ entry.dayLabel }}
                            </span>
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

                        <li
                            :class="
                                cn(
                                    'min-w-0 rounded-md transition-colors duration-700 motion-reduce:transition-none',
                                    highlightedId === entry.message.id && 'bg-primary/10',
                                )
                            "
                            :data-message-id="entry.message.id"
                        >
                            <MessageRow
                                :message="entry.message"
                                :starts-run="entry.startsRun"
                                :links-stale="linksStale"
                                :layout="layout"
                                :author-line="layout === 'sided' && thread.type !== 'dm'"
                                :conversation-id="thread.conversation_id"
                                :can-reply="thread.can_post"
                                @announce="actionStatus = $event"
                                @retry="retry(entry.message.id)"
                                @replace="replaceMessage"
                                @reply="startReply"
                                @jump="jumpTo"
                            />
                        </li>
                    </template>
                </ol>
            </div>

            <!-- Brief 009: a failed background re-read, said quietly; the thread stays. -->
            <p
                v-if="reconnecting"
                class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground"
                role="status"
                data-testid="thread-reconnecting"
            >
                <Loader2 class="size-3 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                Reconnecting…
            </p>

            <!--
                `can_post`, and nothing else. The announcements channel is where it is false for
                most people, which is ConversationPolicy asking for `announcements.send` — not a
                role read in this file.
            -->
            <!--
                Brief 016: pinned, the form is drawn `absolute bottom-0` against the drawer's
                panel (the nearest positioned ancestor, outside the scroll container), so it stays
                put while the body scrolls under it. It is still here in the DOM — Tab order,
                the one instance and its state are unchanged; `DRAWER_FOOTER_INSET` pads the body.
            -->
            <form
                v-if="thread.can_post"
                ref="composerEl"
                :class="
                    cn(
                        'flex min-w-0 shrink-0 flex-col gap-2',
                        composerPinned
                            ? 'absolute inset-x-0 bottom-0 z-10 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]'
                            : 'border-t pt-3',
                    )
                "
                :data-composer-pinned="composerPinned || undefined"
                novalidate
                @submit.prevent="post"
                @paste.capture="onPaste"
            >
                <Label :for="bodyId" class="sr-only">
                    {{ isAnnouncements ? 'Write an announcement' : 'Write a message' }}
                </Label>

                <p v-if="draftRestored" class="text-xs text-muted-foreground" data-testid="draft-restored">
                    Draft restored.
                </p>

                <!-- The picker itself is off-screen; the paperclip is the control. -->
                <Label :for="pickerId" class="sr-only">Attach a file</Label>
                <input
                    :id="pickerId"
                    ref="pickerEl"
                    type="file"
                    multiple
                    :accept="MESSAGE_FILE_ACCEPT"
                    :disabled="posting || attachBlocked"
                    class="sr-only"
                    @change="choose"
                >

                <!--
                    Polish 005: every file waiting to go — an image as a thumbnail, anything else
                    as a chip — each with its own remove control. They go in this order.
                -->
                <ul v-if="attachments.length > 0" class="flex min-w-0 list-none flex-wrap gap-2 self-start">
                    <li v-for="(file, index) in attachments" :key="`${index}-${file.name}-${file.lastModified}`" class="relative">
                        <img
                            v-if="previewFor(file)"
                            :src="previewFor(file) ?? undefined"
                            :alt="file.name"
                            class="size-16 rounded-md border object-cover"
                        >
                        <div
                            v-else
                            class="flex h-16 max-w-48 min-w-0 items-center gap-2 rounded-md border bg-muted/40 py-1 pr-7 pl-2"
                        >
                            <Paperclip class="size-3 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span class="min-w-0 truncate text-xs" :title="file.name">{{ file.name }}</span>
                        </div>
                        <Button
                            type="button"
                            size="icon-xs"
                            variant="secondary"
                            class="absolute -top-1.5 -right-1.5 size-5 rounded-full border shadow-flat"
                            :disabled="posting && index === 0"
                            :aria-label="`Remove ${file.name}`"
                            @click="removeAttachment(index)"
                        >
                            <X aria-hidden="true" />
                        </Button>
                    </li>
                </ul>

                <p
                    v-if="fieldError"
                    :id="errorId"
                    class="flex items-start gap-2 text-xs text-destructive"
                >
                    <CircleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                    {{ fieldError }}
                </p>

                <!-- How far the attachment or voice note has got; the words carry the number. -->
                <div v-if="sendProgress !== null" class="flex min-w-0 flex-col gap-1">
                    <p :id="progressId" class="text-xs text-muted-foreground tabular-nums">
                        Uploading… {{ sendProgress }}%
                    </p>
                    <Progress :model-value="sendProgress" :aria-labelledby="progressId" />
                </div>

                <!-- No answer, or a 5xx. The composer was not reset: the same bytes go again. -->
                <div
                    v-if="sendFailed"
                    role="alert"
                    class="flex flex-col gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                >
                    <p class="flex items-start gap-2 text-xs text-destructive">
                        <CircleAlert class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                        {{ SEND_FAILED_TEXT[sendFailed] }}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="shrink-0"
                        :disabled="posting || stillRecording"
                        @click="post"
                    >
                        <RefreshCw aria-hidden="true" />
                        Try again
                    </Button>
                </div>

                <!--
                    Messaging polish: the composer is ONE pill (DESIGN.md §1.7b) — Attach, the
                    textarea, the mic and "@ Mentions" inside it, and Send a round `--primary` disc
                    inside its right end (12-77; it was a joined segment that overflowed). The textarea has no border or ring of its own; its
                    focus indicator was painted on the pill. Since 2026-09-30 (decision 12-72,
                    superseding 12-70's 1 px `--ring` border) the pill paints NO focus border or
                    ring: the client asked for none on any text field anywhere; the caret shows
                    focus. The pill's border stays `--input` (or `--destructive` on an error). Below `sm` the words go and the icons stay,
                    each keeping its accessible name.

                    `VoiceRecorder` has two roots: its strip (`min-w-0 flex-1 basis-40`) sits in
                    the pill's own row while a clip is in hand, and the mic sits after the
                    textarea.
                -->
                <!--
                    Brief 016, the pinned row: [Attach] [textarea] [mic] [@] [Send], every
                    control the same 32 px ghost icon button with an accessible name and a
                    tooltip, Send the one `--primary` disc. No placeholder: the caret shows focus,
                    and (decision 12-72) the pill paints no focus border.
                -->
                <!-- Brief 013: what this message answers, until it is sent or cancelled. -->
                <ReplyQuote
                    v-if="replyQuote"
                    :reply="replyQuote"
                    variant="composer"
                    :viewer-id="userId"
                    @cancel="cancelReply"
                />

                <div
                    v-if="composerPinned"
                    data-testid="composer-pill"
                    :class="
                        cn(
                            'flex min-w-0 flex-wrap items-end gap-1 rounded-3xl border border-input bg-card p-1 shadow-flat',
                            fieldError && 'border-destructive',
                        )
                    "
                >
                    <TooltipProvider :delay-duration="150">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="shrink-0 rounded-full text-muted-foreground hover:text-foreground"
                                    :disabled="posting || attachBlocked"
                                    :aria-label="
                                        attachBlocked
                                            ? 'Attach a file (unavailable: discard the voice message first)'
                                            : 'Attach a file'
                                    "
                                    data-composer-attach
                                    @click="pickerEl?.click()"
                                >
                                    <Paperclip aria-hidden="true" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                {{ attachBlocked ? 'Discard the voice message first' : 'Attach a file' }}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>

                    <Textarea
                        :id="bodyId"
                        ref="bodyEl"
                        v-model="body"
                        :disabled="posting"
                        :aria-describedby="fieldError ? errorId : undefined"
                        :aria-invalid="fieldError ? true : undefined"
                        rows="1"
                        class="max-h-29 min-h-8 min-w-0 flex-1 basis-24 resize-none overflow-y-auto rounded-none border-0 bg-transparent px-1.5 py-1.5 focus-visible:ring-0 aria-invalid:ring-0 dark:bg-transparent"
                        @input="grow"
                        @keydown.enter="onEnter"
                        @keydown.esc="onComposerEscape"
                    />

                    <EmojiPicker :disabled="posting" @pick="insertEmoji" />

                    <VoiceRecorder
                        v-model:clip="voiceClip"
                        v-model:active="voiceActive"
                        :disabled="posting"
                        :blocked="micBlocked"
                    />

                    <MentionPicker
                        :people="thread.mentionable"
                        :disabled="posting"
                        @pick="mention"
                    />

                    <TooltipProvider :delay-duration="150">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    type="submit"
                                    size="icon-sm"
                                    class="shrink-0 rounded-full"
                                    :disabled="posting || stillRecording"
                                    :aria-label="stillRecording ? 'Send (unavailable while recording)' : posting ? 'Sending' : 'Send'"
                                >
                                    <Send aria-hidden="true" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>{{ stillRecording ? 'Stop recording first' : 'Send' }}</TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>

                <div
                    v-else
                    data-testid="composer-pill"
                    :class="
                        cn(
                            'flex min-w-0 items-stretch rounded-3xl border border-input bg-card shadow-flat transition-[color,box-shadow]',
                            fieldError && 'border-destructive',
                        )
                    "
                >
                    <div class="flex min-w-0 flex-1 flex-wrap items-end gap-1 py-1 pl-1 sm:pl-1.5">
                        <!--
                            12-77: while a recording or a preview is in hand the paperclip, the
                            textarea and Mentions step aside (`v-show`, so the draft text is kept)
                            and the recorder's strip takes their place in this same row, the way
                            Telegram does it. They come back the moment the clip is sent or
                            discarded.
                        -->
                        <TooltipProvider :delay-duration="150">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        v-show="!voiceActive"
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="h-8 shrink-0 gap-1.5 rounded-full px-2 text-muted-foreground hover:text-foreground sm:h-9 sm:px-2.5"
                                        :disabled="posting || attachBlocked"
                                        :aria-label="
                                            attachBlocked
                                                ? 'Attach a file (unavailable: discard the voice message first)'
                                                : 'Attach a file'
                                        "
                                        @click="pickerEl?.click()"
                                    >
                                        <Paperclip aria-hidden="true" />
                                        <span class="hidden sm:inline" aria-hidden="true">Attach</span>
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ attachBlocked ? 'Discard the voice message first' : 'Attach a file' }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>

                        <Textarea
                            v-show="!voiceActive"
                            :id="bodyId"
                            ref="bodyEl"
                            v-model="body"
                            :disabled="posting"
                            :aria-describedby="fieldError ? errorId : undefined"
                            :aria-invalid="fieldError ? true : undefined"
                            rows="1"
                            :placeholder="(placeholder ?? (isAnnouncements ? 'Tell everybody.' : 'Say something.')) || undefined"
                            class="max-h-40 min-h-9 min-w-0 flex-1 basis-24 resize-none overflow-y-auto rounded-none border-0 bg-transparent px-1.5 py-2 focus-visible:ring-0 aria-invalid:ring-0 dark:bg-transparent"
                            @input="grow"
                            @keydown.enter="onEnter"
                            @keydown.esc="onComposerEscape"
                        />

                        <!--
                            Two roots: the strip, which takes the textarea's place in this row
                            (`min-w-0 flex-1 basis-40`) while it is hidden, and the mic button. On
                            a browser that cannot record, both roots are nothing.
                        -->
                        <EmojiPicker v-show="!voiceActive" :disabled="posting" @pick="insertEmoji" />

                        <VoiceRecorder
                            v-model:clip="voiceClip"
                            v-model:active="voiceActive"
                            :disabled="posting"
                            :blocked="micBlocked"
                        />

                        <MentionPicker
                            v-show="!voiceActive"
                            :people="thread.mentionable"
                            :disabled="posting"
                            labelled
                            @pick="mention"
                        />
                    </div>

                    <!--
                        Held while the microphone is open: nothing is uploaded mid-recording, and
                        Enter is blocked on the same condition so the shortcut cannot get round it.
                    -->
                    <Button
                        type="submit"
                        class="m-1 size-10 shrink-0 self-end rounded-full p-0"
                        :disabled="posting || stillRecording"
                        :aria-label="stillRecording ? 'Send (unavailable while recording)' : posting ? 'Sending' : 'Send'"
                    >
                        <Send aria-hidden="true" />
                    </Button>
                </div>

            </form>
        </template>
    </div>
</template>
