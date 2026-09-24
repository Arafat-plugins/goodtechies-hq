<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import AttachmentCard from '@/Components/Messages/AttachmentCard.vue';
import MessageBody from '@/Components/Messages/MessageBody.vue';
import type { ThreadLayout, ThreadMessage } from '@/Components/Messages/messages';
import { formatClockTime, initialsOf } from '@/Components/Messages/messages';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
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
 * viewer's own messages sit RIGHT in a solid `--primary` bubble; the other person's sit LEFT on
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
 * ## One hover action, because there is one action
 *
 * **Copy text.** There are no reactions, no replies, no pin and no edit or delete in this
 * application — no table, no column, no endpoint — so no control for them is drawn, not even a
 * disabled one. The copy control is reachable by keyboard: it is revealed by
 * `group-focus-within` as well as by `group-hover`, so tabbing into the row shows it, and it is
 * never removed from the document.
 *
 * In `sided` it sits OUTSIDE the bubble, on the card surface. That is deliberate: it keeps the
 * one control in the row on a background its `--ring` focus ring was actually measured against
 * (3.61:1 / 6.13:1) instead of against a brand fill, where `ring-ring/50` composites to 1.19:1.
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
    }>(),
    { layout: 'stacked' },
);

/** Spoken by the thread's one live region — a row does not get a live region of its own. */
const emit = defineEmits<{ announce: [message: string] }>();

const copied = ref(false);
let settle: ReturnType<typeof setTimeout> | undefined;

const sided = computed(() => props.layout === 'sided');
const mine = computed(() => props.message.is_mine);

/** Everything in here is sitting on a `--primary` fill and cannot use hue to say anything. */
const onAccent = computed(() => sided.value && mine.value);

const author = computed(() =>
    props.message.is_mine ? 'You' : (props.message.author?.name ?? 'Somebody who has since left'),
);

const clock = computed(() => formatClockTime(props.message.created_at));

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
        'flex min-w-0 flex-col gap-1 rounded-xl px-3 py-2',
        mine.value
            ? 'rounded-br-sm bg-primary text-primary-foreground'
            : 'rounded-bl-sm border bg-muted text-foreground',
        // The mention highlight has to survive on both fills, so it is a ring rather than a
        // left bar here: `--primary` on `--muted` is 4.71:1 and `--primary-foreground` on
        // `--primary` is 4.99:1, both clear of the 3:1 a boundary needs.
        props.message.mentions_me && 'ring-2',
        props.message.mentions_me && (mine.value ? 'ring-primary-foreground' : 'ring-primary'),
    ),
);

/** The `mentions_me` label. A chip, not a hairline — and it is words, so it is never a tint. */
const mentionChipClass = computed(() =>
    cn(
        'inline-flex w-fit shrink-0 items-center rounded-full px-1.5 text-xs font-medium',
        onAccent.value ? 'bg-primary-foreground text-primary' : 'bg-primary text-primary-foreground',
    ),
);

async function copy(): Promise<void> {
    const text = props.message.body ?? '';

    if (text === '') {
        return;
    }

    try {
        await navigator.clipboard.writeText(text);

        copied.value = true;
        emit('announce', 'Message copied.');

        clearTimeout(settle);
        settle = setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // Clipboard access can be refused outright (an insecure origin, a permission policy).
        // Saying so is better than a tick that means nothing happened.
        emit('announce', 'That message could not be copied.');
    }
}
</script>

<template>
    <!-- ───────────────────────────────────────────────── a DM: two sides, no names -->
    <div
        v-if="sided"
        :class="
            cn(
                'group relative flex min-w-0',
                startsRun ? 'mt-2' : 'mt-0.5',
                mine ? 'justify-end' : 'justify-start',
            )
        "
    >
        <div
            :class="
                cn(
                    'flex min-w-0 max-w-4/5 items-end gap-1 sm:max-w-3/4 lg:max-w-3/5',
                    mine && 'flex-row-reverse',
                )
            "
        >
            <div :class="bubbleClass">
                <!--
                    Addressed to this reader. The words carry it; the ring on the bubble is the
                    second carrier, never the only one — and it is on EVERY row of the run, not
                    just the first, because a continuation row has no author line to fall back on.
                -->
                <span v-if="message.mentions_me" :class="mentionChipClass">Mentions you</span>

                <MessageBody
                    v-if="message.body"
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
                    it to hang it from. `text-primary-foreground` at 4.99:1 rather than a muted
                    grey — any alpha on top of that number puts it under 4.5:1, so the size is
                    what makes it quiet, not the colour.
                -->
                <span
                    :class="
                        cn(
                            'self-end text-xs tabular-nums',
                            mine ? 'text-primary-foreground' : 'text-muted-foreground',
                        )
                    "
                >
                    {{ clock }}
                </span>
            </div>

            <TooltipProvider v-if="message.body" :delay-duration="150">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            :aria-label="copied ? 'Message copied' : 'Copy message text'"
                            class="mb-1 flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-accent-foreground group-hover:opacity-100 group-focus-within:opacity-100 focus-visible:opacity-100 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none"
                            @click="copy"
                        >
                            <Check v-if="copied" class="size-3.5" aria-hidden="true" />
                            <Copy v-else class="size-3.5" aria-hidden="true" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>{{ copied ? 'Copied' : 'Copy text' }}</TooltipContent>
                </Tooltip>
            </TooltipProvider>
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
                // the clock and the copy control fading in, which is unchanged and does not
                // move a single foreground onto a new surface.
                message.is_mine ? 'bg-brand-tint' : 'hover:bg-muted/60',
                // Addressed to this reader. The word in the line below carries it; this rule is
                // the second carrier, never the only one.
                message.mentions_me && 'border-l-4 border-primary pl-1',
            )
        "
    >
        <Avatar v-if="startsRun" class="mt-0.5 size-8">
            <AvatarFallback class="text-xs font-medium">
                {{ initialsOf(message.author?.name) }}
            </AvatarFallback>
        </Avatar>
        <span v-else class="size-8 shrink-0" aria-hidden="true" />

        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <p
                v-if="startsRun"
                class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5"
            >
                <!--
                    A name reads as a name: full foreground and weight, so it is not mistaken for
                    the clock sitting next to it at the same grey.
                -->
                <span class="text-sm font-semibold break-words text-foreground">{{ author }}</span>
                <span class="text-xs tabular-nums text-muted-foreground">{{ clock }}</span>
                <span v-if="message.mentions_me" :class="mentionChipClass">Mentions you</span>
            </p>

            <!--
                A continuation row that names this reader still has to say so: it has no author
                line to carry the word, and a border on its own is colour alone.
            -->
            <span v-else-if="message.mentions_me" :class="mentionChipClass">Mentions you</span>

            <MessageBody
                v-if="message.body"
                :body="message.body"
                :mentions="message.mentions"
            />

            <ul v-if="message.attachments.length > 0" class="flex min-w-0 flex-col gap-2 pt-0.5">
                <li v-for="file in message.attachments" :key="file.id" class="min-w-0">
                    <AttachmentCard :file="file" :stale="linksStale" inline />
                </li>
            </ul>
        </div>

        <div class="flex shrink-0 items-start gap-1 pt-0.5">
            <span
                v-if="!startsRun"
                class="text-xs tabular-nums text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 motion-reduce:transition-none"
            >
                {{ clock }}
            </span>

            <TooltipProvider v-if="message.body" :delay-duration="150">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            :aria-label="copied ? 'Message copied' : 'Copy message text'"
                            class="flex size-7 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-accent-foreground group-hover:opacity-100 group-focus-within:opacity-100 focus-visible:opacity-100 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none"
                            @click="copy"
                        >
                            <Check v-if="copied" class="size-3.5" aria-hidden="true" />
                            <Copy v-else class="size-3.5" aria-hidden="true" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>{{ copied ? 'Copied' : 'Copy text' }}</TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
    </div>
</template>
