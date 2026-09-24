<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import AttachmentCard from '@/Components/Messages/AttachmentCard.vue';
import MessageBody from '@/Components/Messages/MessageBody.vue';
import type { ThreadMessage } from '@/Components/Messages/messages';
import { formatClockTime, initialsOf } from '@/Components/Messages/messages';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * One message in the log.
 *
 * The first message of a run by one person carries the avatar and the name; the rest are tight
 * rows whose clock appears on hover or focus. That is the whole of the grouping — the decision
 * about *which* rows those are was made once, for the whole list, by `renderThread()`.
 *
 * ## The avatar is initials, and that is not a placeholder
 *
 * There is no avatar column on `users` and this slice did not add one. Two letters on a neutral
 * medallion is what the data supports, so it is what is drawn.
 *
 * ## One hover action, because there is one action
 *
 * **Copy text.** There are no reactions, no replies, no pin and no edit or delete in this
 * application — no table, no column, no endpoint — so no control for them is drawn, not even a
 * disabled one. The copy control is reachable by keyboard: it is revealed by
 * `group-focus-within` as well as by `group-hover`, so tabbing into the row shows it, and it is
 * never removed from the document.
 */

const props = defineProps<{
    message: ThreadMessage;
    /** First of a run: draw the avatar and the author line. */
    startsRun: boolean;
    /** The signed attachment links on this payload have lapsed. */
    linksStale: boolean;
}>();

/** Spoken by the thread's one live region — a row does not get a live region of its own. */
const emit = defineEmits<{ announce: [message: string] }>();

const copied = ref(false);
let settle: ReturnType<typeof setTimeout> | undefined;

const author = computed(() =>
    props.message.is_mine ? 'You' : (props.message.author?.name ?? 'Somebody who has since left'),
);

const clock = computed(() => formatClockTime(props.message.created_at));

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
    <div
        :class="
            cn(
                'group relative flex min-w-0 gap-2 rounded-md py-0.5 pr-1 pl-2 hover:bg-muted/60',
                startsRun && 'mt-2 pt-1',
                // Addressed to this reader. The word in the line below carries it; this rule is
                // the second carrier, never the only one.
                message.mentions_me && 'border-l-2 border-primary pl-1.5',
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
                <span class="text-sm font-medium break-words">{{ author }}</span>
                <span class="text-xs tabular-nums text-muted-foreground">{{ clock }}</span>
                <span v-if="message.mentions_me" class="text-xs font-medium text-primary">
                    Mentions you
                </span>
            </p>

            <!--
                A continuation row that names this reader still has to say so: it has no author
                line to carry the word, and a border on its own is colour alone.
            -->
            <p v-else-if="message.mentions_me" class="text-xs font-medium text-primary">
                Mentions you
            </p>

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
