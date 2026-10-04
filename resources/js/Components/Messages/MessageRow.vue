<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, CheckCheck, CircleAlert, Clock } from '@lucide/vue';
import type { FunctionalComponent, VNode } from 'vue';
import { computed, h, nextTick, ref } from 'vue';
import AttachmentCard from '@/Components/Messages/AttachmentCard.vue';
import { rememberEmoji } from '@/Components/Messages/emoji';
import MessageActions from '@/Components/Messages/MessageActions.vue';
import MessageBody from '@/Components/Messages/MessageBody.vue';
import ReactionBar from '@/Components/Messages/ReactionBar.vue';
import ReplyQuote from '@/Components/Messages/ReplyQuote.vue';
import type { ThreadLayout, ThreadMessage } from '@/Components/Messages/messages';
import {
    MESSAGE_MAX_BODY,
    formatClockTime,
    initialsOf,
    messageFrom,
    messageRequest,
    messageUrl,
    refusalText,
    toggleReaction,
} from '@/Components/Messages/messages';
import { personTone } from '@/Components/Messages/people';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

/**
 * One message in the log, in one of two treatments.
 *
 * ## `stacked` — every channel
 *
 * Team, announcements, a project channel, a task discussion. Everything stays left with its
 * avatar and its author line, because in a room of eight people "who said this" is the fact
 * worth carrying and forty bubbles alternating sides is noise. The first message of a run
 * carries the avatar and the name; the rest are tight rows whose clock appears on hover or
 * focus. What is new is that the viewer's OWN messages carry `--brand-tint`, so scrolling back
 * to find yourself is a glance rather than a read.
 *
 * ## `sided` — a DM
 *
 * Two people talking, drawn the way every messaging app on the client's phone draws it. The
 * viewer's own messages sit RIGHT in a solid `--bubble-own` bubble (bubble-own, 12-77); the other person's sit LEFT on
 * `--muted`. The author name drops out entirely — there are two people and the side says which
 * — and so does the avatar, because a column of the same two faces down a two-person
 * conversation carries nothing. The clock stays, inside the bubble.
 *
 * ## Nothing here is carried by colour alone (DESIGN.md §5.6)
 *
 * | Fact | Colour | Second carrier |
 * | --- | --- | --- |
 * | mine vs theirs, DM | bubble fill | the SIDE, and the bubble's tail corner |
 * | mine vs theirs, channel | row tint | the author line, which says "You" |
 * | this names you | the bar and the chip | the words "Mentions you", on a run's first row AND on a continuation row |
 * | a mention in the body | `--primary`, or weight on accent | `font-medium` and an `sr-only` "mentioned" |
 *
 * ## The avatar is initials, and that is not a placeholder
 *
 * There is no avatar column on `users` and this slice did not add one. Two letters on a neutral
 * medallion is what the data supports, so it is what is drawn — in a channel, where it tells
 * eight people apart.
 *
 * ## The message menu (brief 010)
 *
 * Reply (brief 013), Copy, Edit, Delete and quick reactions live in `MessageActions` — a `MoreHorizontal` button
 * revealed by `group-hover` and `group-focus-within` (always on for a touch screen), or a
 * right-click / long-press on the bubble. Edit and Delete exist only where the server said
 * `can_edit` / `can_delete`. A pending, failed or deleted message has no menu at all.
 *
 * In `sided` the button sits OUTSIDE the bubble, on the card surface. That is deliberate: it
 * keeps the control on a background its `--ring` focus ring was actually measured against
 * (3.61:1 / 6.15:1) instead of against a brand fill, where `--ring` is 1.43:1.
 *
 * Every edit, delete and reaction answers with the server's `message`, which this row hands up
 * as `replace` — the thread folds it in through the same merge a re-read uses.
 */

const props = withDefaults(
    defineProps<{
        message: ThreadMessage;
        /** First of a run: in a channel, draw the avatar and the author line. */
        startsRun: boolean;
        /** The signed attachment links on this payload have lapsed. */
        linksStale: boolean;
        /** Which treatment this conversation gets. `threadLayout()` decides it, once. */
        layout?: ThreadLayout;
        /** The conversation the message is in — the edit / delete / reaction endpoints hang off it. */
        conversationId?: number | null;
        /** Brief 013: the thread has a composer, so the menu offers Reply. */
        canReply?: boolean;
        /**
         * Telegram style in a group or channel: an incoming run starts with the author's name
         * inside the bubble, and a small avatar sits beside it. A DM passes false.
         */
        authorLine?: boolean;
    }>(),
    { layout: 'stacked', conversationId: null, canReply: false, authorLine: false },
);

/** Spoken by the thread's one live region — a row does not get a live region of its own. */
const emit = defineEmits<{
    announce: [message: string];
    /** "Not sent — tap to retry" was pressed on a failed optimistic send. */
    retry: [];
    /** Brief 010: the server's (or an optimistic) new version of this message. */
    replace: [message: ThreadMessage];
    /** Brief 013: Reply was chosen from this message's menu. */
    reply: [message: ThreadMessage];
    /** Brief 013: the quoted original was clicked — scroll to message `id`. */
    jump: [id: number];
}>();

const page = usePage();
const viewerId = computed(() => page.props.auth.user?.id ?? null);

const sided = computed(() => props.layout === 'sided');
const mine = computed(() => props.message.is_mine);
const deleted = computed(() => props.message.is_deleted === true);
const mentionsMe = computed(() => props.message.mentions_me && !deleted.value);

/** Everything in here is sitting on the `--bubble-own` fill (bubble-own, 12-77) and cannot use hue to say anything. */
const onAccent = computed(() => sided.value && mine.value);

/** A real, settled, not-deleted message — the only kind with a menu and reactions. */
const live = computed(
    () =>
        props.message.id > 0 &&
        !props.message.pending &&
        !props.message.failed &&
        !deleted.value &&
        props.conversationId !== null,
);

const author = computed(() =>
    props.message.is_mine ? 'You' : (props.message.author?.name ?? 'Somebody who has since left'),
);

/**
 * Messaging polish: one colour per person (`people.ts`), on the avatar and on the name, so a
 * team channel reads by who is talking. Your own rows keep the neutral name — they already
 * wear the brand tint and say "You".
 */
const tone = computed(() => personTone(props.message.author?.id));

const clock = computed(() => formatClockTime(props.message.created_at));

/**
 * Brief 009, Telegram's inline time: a message with text and no attachment carries its clock at
 * the end of its LAST line — on the same line when it is one line. An invisible copy of the
 * stamp is laid out inline after the text to reserve the room, and the visible one sits on top
 * of that room at the bottom-right corner, so the two never overlap. A message with media keeps
 * the clock under the media.
 */
const inlineStamp = computed(() => Boolean(props.message.body) && props.message.attachments.length === 0);

/** The stamp's colour: quiet on either DM fill, muted on a channel row. */
const stampTone = computed(() =>
    sided.value && mine.value ? 'text-bubble-own-foreground/80' : 'text-muted-foreground',
);

/** Brief 010: ✓ sent / ✓✓ seen, on the viewer's own DM messages only (`seen` is null elsewhere). */
const tick = computed<'sent' | 'seen' | null>(() => {
    const message = props.message;

    if (!message.is_mine || message.seen === null || message.seen === undefined || message.pending || message.failed) {
        return null;
    }

    return message.seen ? 'seen' : 'sent';
});

const tickClass = computed(() => {
    if (onAccent.value) {
        return tick.value === 'seen' ? 'text-bubble-own-foreground' : 'text-bubble-own-foreground/70';
    }

    return tick.value === 'seen' ? 'text-foreground' : 'text-muted-foreground';
});

/**
 * What the stamp says: `edited`, the clock (or the Sending clock face), and the tick. Drawn twice
 * for an inline stamp — once invisibly to reserve the room, once on top — so it is one function.
 */
const StampContent: FunctionalComponent<{ spacer?: boolean }> = (stampProps) => {
    const parts: VNode[] = [];
    const spoken = stampProps.spacer !== true;

    if (props.message.edited_at) {
        parts.push(h('span', null, 'edited'));
    }

    if (props.message.pending) {
        parts.push(h(Clock, { class: 'size-3', 'aria-label': spoken ? 'Sending' : undefined }));
    } else {
        parts.push(h('span', null, clock.value));
    }

    if (tick.value !== null) {
        parts.push(
            h(tick.value === 'seen' ? CheckCheck : Check, {
                class: cn('size-3.5', tickClass.value),
                'aria-label': spoken ? (tick.value === 'seen' ? 'Seen' : 'Sent') : undefined,
            }),
        );
    }

    return parts;
};

/**
 * The bubble, in a DM.
 *
 * `rounded-xl` against the channel row's `rounded-md` is the "this is a bubble" signal, and the
 * squared-off tail corner (`rounded-br-sm` / `rounded-bl-sm`) points at its own side — a second,
 * colour-free carrier of who spoke. The max widths keep a one-word reply a one-word bubble
 * instead of a full-width band, and tighten as the column gets wider.
 */
const bubbleClass = computed(() =>
    cn(
        'flex min-w-0 flex-col gap-1 rounded-2xl px-3 py-1.5',
        mine.value
            ? 'rounded-br-md bg-bubble-own text-bubble-own-foreground'
            : 'rounded-bl-md border bg-card text-foreground',
        // The mention highlight has to survive on both fills, so it is a ring rather than a
        // left bar here: `--primary` on `--muted` is 4.71:1 and `--bubble-own-foreground` on
        // `--bubble-own` is ~9:1 (bubble-own, 12-77), both clear of the 3:1 a boundary needs.
        mentionsMe.value && 'ring-2',
        mentionsMe.value && (mine.value ? 'ring-bubble-own-foreground' : 'ring-primary'),
    ),
);

/** The `mentions_me` label. A chip, not a hairline — and it is words, so it is never a tint. */
const mentionChipClass = computed(() =>
    cn(
        'inline-flex w-fit shrink-0 items-center rounded-full px-1.5 text-xs font-medium',
        onAccent.value ? 'bg-bubble-own-foreground text-bubble-own' : 'bg-primary text-primary-foreground',
    ),
);

/* ------------------------------------------------------------------ the menu */

const actionsEl = ref<InstanceType<typeof MessageActions> | null>(null);

/** Right-click (or a long-press, which browsers report the same way) opens the message menu. */
function onContextMenu(event: MouseEvent): void {
    if (!live.value || editing.value) {
        return;
    }

    event.preventDefault();
    actionsEl.value?.openMenu();
}

async function copy(): Promise<void> {
    const text = props.message.body ?? '';

    if (text === '') {
        return;
    }

    try {
        await navigator.clipboard.writeText(text);

        emit('announce', 'Message copied.');
    } catch {
        // Clipboard access can be refused outright (an insecure origin, a permission policy).
        // Saying so is better than a tick that means nothing happened.
        emit('announce', 'That message could not be copied.');
    }
}

function url(): string {
    return messageUrl(props.conversationId ?? 0, props.message.id);
}

/* ------------------------------------------------------------------ edit */

const editing = ref(false);
const draft = ref('');
const saving = ref(false);
const editError = ref<string | null>(null);
const editorEl = ref<InstanceType<typeof Textarea> | null>(null);

function startEdit(): void {
    draft.value = props.message.body ?? '';
    editError.value = null;
    editing.value = true;

    void nextTick(() => {
        const field = editorEl.value?.$el as HTMLTextAreaElement | undefined;

        field?.focus();
        field?.setSelectionRange(field.value.length, field.value.length);
    });
}

function cancelEdit(): void {
    editing.value = false;
    editError.value = null;
}

async function saveEdit(): Promise<void> {
    if (saving.value) {
        return;
    }

    if (draft.value.trim() === '') {
        editError.value = 'A message cannot be empty. Delete it instead.';

        return;
    }

    if (draft.value === (props.message.body ?? '')) {
        cancelEdit();

        return;
    }

    saving.value = true;
    editError.value = null;

    try {
        const answer = await messageRequest('PATCH', url(), { body: draft.value });
        const fresh = messageFrom(answer.json);

        if (answer.status >= 200 && answer.status < 300 && fresh !== null) {
            emit('replace', fresh);
            editing.value = false;
            emit('announce', 'Message edited.');
        } else {
            editError.value = answer.status === 422 ? refusalText(answer.json) : 'That edit could not be saved. Try again.';
        }
    } catch {
        editError.value = 'No connection. That edit was not saved.';
    } finally {
        saving.value = false;
    }
}

function onEditKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        cancelEdit();

        return;
    }

    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void saveEdit();
    }
}

/* ------------------------------------------------------------------ delete */

async function remove(): Promise<void> {
    try {
        const answer = await messageRequest('DELETE', url(), null);
        const fresh = messageFrom(answer.json);

        if (answer.status >= 200 && answer.status < 300 && fresh !== null) {
            emit('replace', fresh);
            emit('announce', 'Message deleted.');
        } else {
            emit('announce', 'That message could not be deleted.');
        }
    } catch {
        emit('announce', 'No connection. That message was not deleted.');
    }
}

/* ------------------------------------------------------------------ react */

/** Optimistic: the chip moves at once, then the server's answer replaces it (or undoes it). */
async function react(emoji: string): Promise<void> {
    if (!live.value) {
        return;
    }

    rememberEmoji(emoji);

    const before = props.message;

    emit('replace', toggleReaction(before, emoji, page.props.auth.user?.name ?? 'You'));

    try {
        const answer = await messageRequest('POST', `${url()}/reactions`, { emoji });
        const fresh = messageFrom(answer.json);

        if (answer.status >= 200 && answer.status < 300 && fresh !== null) {
            emit('replace', fresh);

            return;
        }
    } catch {
        // Falls through to the undo below.
    }

    emit('replace', before);
    emit('announce', 'That reaction could not be saved.');
}
</script>

<template>
    <!-- ───────────────────────────────────────────────── a DM: two sides, no names -->
    <div
        v-if="sided"
        :class="
            cn(
                'group relative flex min-w-0',
                startsRun ? 'mt-2.5' : 'mt-0.5',
                mine ? 'justify-end' : 'justify-start',
                authorLine && !mine && 'gap-2',
            )
        "
    >
        <!-- Telegram style in a group or channel: a small avatar beside an incoming run. -->
        <template v-if="authorLine && !mine">
            <Avatar v-if="startsRun" class="mt-0.5 size-8 shrink-0">
                <AvatarFallback :class="cn('text-xs font-medium', tone.avatar)">
                    {{ initialsOf(message.author?.name) }}
                </AvatarFallback>
            </Avatar>
            <span v-else class="size-8 shrink-0" aria-hidden="true" />
        </template>
        <div
            :class="
                cn(
                    'flex min-w-0 max-w-[85%] flex-col gap-1 sm:max-w-3/4 lg:max-w-3/5',
                    mine ? 'items-end' : 'items-start',
                    editing && 'w-full',
                )
            "
        >
            <div :class="cn('flex min-w-0 max-w-full items-end gap-1', mine && 'flex-row-reverse', editing && 'w-full')">
                <!-- Brief 010: Edit turns the bubble into the field, on the card surface. -->
                <div v-if="editing" class="flex w-full min-w-0 flex-col gap-2 rounded-xl border bg-card p-2">
                    <Textarea
                        ref="editorEl"
                        v-model="draft"
                        :maxlength="MESSAGE_MAX_BODY"
                        aria-label="Edit message"
                        :aria-invalid="editError !== null ? 'true' : undefined"
                        class="max-h-60 min-h-9 resize-none"
                        @keydown="onEditKeydown"
                    />
                    <p v-if="editError" class="text-xs text-destructive" role="alert">{{ editError }}</p>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <Button type="button" variant="outline" size="sm" @click="cancelEdit">Cancel</Button>
                        <Button type="button" size="sm" :disabled="saving" @click="saveEdit">Save</Button>
                    </div>
                </div>

                <div v-else :class="bubbleClass" @contextmenu="onContextMenu">
                    <!-- Brief 010: deleted for everyone — no body, no files, no menu, no reactions. -->
                    <p v-if="deleted" :class="cn('flex flex-wrap items-baseline gap-x-2 text-sm italic', stampTone)">
                        This message was deleted
                        <span class="text-xs not-italic tabular-nums">{{ clock }}</span>
                    </p>

                    <template v-else>
                        <span v-if="authorLine && !mine && startsRun" :class="cn('text-xs font-semibold', tone.name)">{{ author }}</span>

                        <!--
                            Addressed to this reader. The words carry it; the ring on the bubble is the
                            second carrier, never the only one — and it is on EVERY row of the run, not
                            just the first, because a continuation row has no author line to fall back on.
                        -->
                        <span v-if="mentionsMe" :class="mentionChipClass">Mentions you</span>

                        <ReplyQuote
                            v-if="message.reply_to"
                            :reply="message.reply_to"
                            :on-accent="onAccent"
                            :viewer-id="viewerId"
                            @jump="emit('jump', $event)"
                        />

                        <div
                            v-if="inlineStamp"
                            class="relative w-fit max-w-full min-w-0 text-sm break-words whitespace-pre-line"
                        >
                            <MessageBody
                                class="inline"
                                :body="message.body ?? ''"
                                :mentions="message.mentions"
                                :on-accent="onAccent"
                            />
                            <span class="invisible ml-2 inline-flex h-4 items-center gap-1 text-xs leading-none tabular-nums" aria-hidden="true">
                                <StampContent spacer />
                            </span>
                            <span :class="cn('absolute right-0 bottom-0 inline-flex h-5 items-center gap-1 text-xs leading-none tabular-nums', stampTone)">
                                <StampContent />
                            </span>
                        </div>

                        <MessageBody
                            v-else-if="message.body"
                            :body="message.body"
                            :mentions="message.mentions"
                            :on-accent="onAccent"
                        />

                        <ul v-if="message.attachments.length > 0" class="flex min-w-0 flex-col gap-2 pt-0.5">
                            <li v-for="file in message.attachments" :key="file.id" class="min-w-0">
                                <AttachmentCard
                                    :file="file"
                                    :stale="linksStale"
                                    :on-accent="onAccent"
                                    inline
                                />
                            </li>
                        </ul>

                        <!--
                            The clock stays, and in a DM it is always on: there is no author line above
                            it to hang it from. `text-bubble-own-foreground/80` (bubble-own, 12-77): the
                            ~9:1 base leaves room for a little alpha and still clears 4.5:1, so the size
                            and that alpha make it quiet.
                        -->
                        <span
                            v-if="!inlineStamp"
                            :class="cn('inline-flex items-center gap-1 self-end text-xs tabular-nums', stampTone)"
                        >
                            <StampContent />
                        </span>

                        <button
                            v-if="message.failed"
                            type="button"
                            class="flex items-center gap-1 self-end rounded-sm text-xs font-medium underline-offset-2 hover:underline focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                            @click="emit('retry')"
                        >
                            <CircleAlert class="size-3" aria-hidden="true" />
                            Not sent — tap to retry
                        </button>
                    </template>
                </div>

                <MessageActions
                    v-if="live && !editing"
                    ref="actionsEl"
                    class="mb-1"
                    :message="message"
                    :align="mine ? 'end' : 'start'"
                    :can-reply="canReply"
                    @reply="emit('reply', message)"
                    @copy="copy"
                    @edit="startEdit"
                    @delete="remove"
                    @react="react"
                />
            </div>

            <ReactionBar
                v-if="!deleted && message.reactions.length > 0"
                :reactions="message.reactions"
                :disabled="!live"
                :class="mine && 'justify-end'"
                @toggle="react"
            />
        </div>
    </div>

    <!-- ──────────────────────────────────── a channel: one side, avatars, author lines -->
    <div
        v-else
        :class="
            cn(
                'group relative flex min-w-0 gap-2 rounded-md py-0.5 pr-1 pl-2',
                startsRun && 'mt-2 pt-1',
                // Find yourself while scrolling. Measured: foreground on brand-tint is 12.31:1
                // light and 14.45:1 dark, muted-foreground on it is 4.97:1 and 5.90:1, so
                // everything this row already draws still passes on the tint. The author line,
                // which reads You, is what carries the fact without the tint.
                //
                // An own row does NOT darken on hover, and that is a measurement and not an
                // oversight: brand-tint-strong is the documented hover for a selected row, but
                // under it the clock falls to 4.47:1 and a mention to 4.16:1 in light mode,
                // both under the 4.5:1 body-text floor. The hover affordance on this row is
                // the clock and the menu control fading in, which is unchanged and does not
                // move a single foreground onto a new surface.
                message.is_mine ? 'bg-brand-tint' : 'hover:bg-muted/60',
                // Addressed to this reader. The word in the line below carries it; this rule is
                // the second carrier, never the only one.
                mentionsMe && 'border-l-4 border-primary pl-1',
            )
        "
    >
        <Avatar v-if="startsRun" class="mt-0.5 size-8">
            <AvatarFallback :class="cn('text-xs font-medium', tone.avatar)">
                {{ initialsOf(message.author?.name) }}
            </AvatarFallback>
        </Avatar>
        <span v-else class="size-8 shrink-0" aria-hidden="true" />

        <div class="flex min-w-0 flex-1 flex-col gap-1" @contextmenu="onContextMenu">
            <p
                v-if="startsRun"
                class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5"
            >
                <!--
                    A name reads as a name: full foreground and weight, so it is not mistaken for
                    the clock sitting next to it at the same grey.
                -->
                <span
                    :class="cn('min-w-0 text-sm font-semibold break-words', message.is_mine ? 'text-foreground' : tone.name)"
                >{{ author }}</span>
                <span v-if="mentionsMe" :class="mentionChipClass">Mentions you</span>
            </p>

            <!--
                A continuation row that names this reader still has to say so: it has no author
                line to carry the word, and a border on its own is colour alone.
            -->
            <span v-else-if="mentionsMe" :class="mentionChipClass">Mentions you</span>

            <p v-if="deleted" class="flex flex-wrap items-baseline gap-x-2 text-sm text-muted-foreground italic">
                This message was deleted
                <span class="text-xs not-italic tabular-nums">{{ clock }}</span>
            </p>

            <div v-else-if="editing" class="flex w-full min-w-0 flex-col gap-2 rounded-md border bg-card p-2">
                <Textarea
                    ref="editorEl"
                    v-model="draft"
                    :maxlength="MESSAGE_MAX_BODY"
                    aria-label="Edit message"
                    :aria-invalid="editError !== null ? 'true' : undefined"
                    class="max-h-60 min-h-9 resize-none"
                    @keydown="onEditKeydown"
                />
                <p v-if="editError" class="text-xs text-destructive" role="alert">{{ editError }}</p>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Button type="button" variant="outline" size="sm" @click="cancelEdit">Cancel</Button>
                    <Button type="button" size="sm" :disabled="saving" @click="saveEdit">Save</Button>
                </div>
            </div>

            <template v-else>
                <ReplyQuote
                    v-if="message.reply_to"
                    :reply="message.reply_to"
                    :viewer-id="viewerId"
                    class="self-start"
                    @jump="emit('jump', $event)"
                />

                <div
                    v-if="inlineStamp"
                    class="relative w-fit max-w-full min-w-0 text-sm break-words whitespace-pre-line"
                >
                    <MessageBody class="inline" :body="message.body ?? ''" :mentions="message.mentions" />
                    <span class="invisible ml-2 inline-flex h-4 items-center gap-1 text-xs leading-none tabular-nums" aria-hidden="true">
                        <StampContent spacer />
                    </span>
                    <span :class="cn('absolute right-0 bottom-0 inline-flex h-5 items-center gap-1 text-xs leading-none tabular-nums', stampTone)">
                        <StampContent />
                    </span>
                </div>

                <MessageBody
                    v-else-if="message.body"
                    :body="message.body"
                    :mentions="message.mentions"
                />

                <ul v-if="message.attachments.length > 0" class="flex min-w-0 flex-col gap-2 pt-0.5">
                    <li v-for="file in message.attachments" :key="file.id" class="min-w-0">
                        <AttachmentCard :file="file" :stale="linksStale" inline />
                    </li>
                </ul>

                <!-- With media, or with nothing to read, the clock sits under it. -->
                <span v-if="!inlineStamp" :class="cn('inline-flex items-center gap-1 text-xs tabular-nums', stampTone)">
                    <StampContent />
                </span>

                <button
                    v-if="message.failed"
                    type="button"
                    class="flex w-fit items-center gap-1 rounded-sm text-xs font-medium text-destructive underline-offset-2 hover:underline focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                    @click="emit('retry')"
                >
                    <CircleAlert class="size-3" aria-hidden="true" />
                    Not sent — tap to retry
                </button>

                <ReactionBar
                    v-if="message.reactions.length > 0"
                    :reactions="message.reactions"
                    :disabled="!live"
                    @toggle="react"
                />
            </template>
        </div>

        <div class="flex shrink-0 items-start gap-1 pt-0.5">
            <MessageActions
                v-if="live && !editing"
                ref="actionsEl"
                :message="message"
                align="end"
                :can-reply="canReply"
                @reply="emit('reply', message)"
                @copy="copy"
                @edit="startEdit"
                @delete="remove"
                @react="react"
            />
        </div>
    </div>
</template>
