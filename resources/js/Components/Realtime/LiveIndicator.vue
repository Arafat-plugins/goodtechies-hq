<script setup lang="ts">
import { computed } from 'vue';
import type { LiveTransport } from '@/Components/Realtime/live';
import { liveTransportIcon, liveTransportLabel, liveTransportWord } from '@/Components/Realtime/live';

/**
 * How this screen is keeping itself current, said quietly and said honestly.
 *
 * The same eight lines were written three times over the messaging slice — the thread header, the
 * Messages page's own header, and the Board wanted a fourth — so it is a component. What it draws
 * is unchanged from what those three drew, and the rules in it are the reason it is not an icon:
 *
 * - The **word is the fact**. A screen reader always gets the whole sentence and from `sm` the
 *   short form is on screen, so nothing here is carried by the icon or by colour alone
 *   (DESIGN.md §5.6).
 * - It is **never "Live" on a polling build**. `liveTransportWord` says `Every 20s` there, which
 *   is true, and the client's machine is that build.
 * - `pending` is the one thing the Board added: a refresh that arrived mid-drag was refused and
 *   is waiting. Saying so is the difference between "your colleague's move will land in a moment"
 *   and a board that looks stale for as long as the card is held.
 */

const props = withDefaults(
    defineProps<{
        transport: LiveTransport;
        /** The interval this screen polls on, so the sentence can name it. */
        intervalMs: number;
        /** What this screen is waiting for, plural — "new messages", "moves by other people". */
        subject?: string;
        /** A refresh is held until a gesture finishes. See `useLiveRefresh`'s `pending`. */
        pending?: boolean;
    }>(),
    { subject: 'new messages', pending: false },
);

const word = computed(() => liveTransportWord(props.transport, props.intervalMs));
const icon = computed(() => liveTransportIcon(props.transport));

const label = computed(() => {
    const sentence = liveTransportLabel(props.transport, props.intervalMs, props.subject);

    return props.pending ? `${sentence} An update is waiting until you finish.` : sentence;
});
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-1 text-xs text-muted-foreground" :title="label">
        <component :is="icon" class="size-3.5 shrink-0" aria-hidden="true" />
        <!-- Polish 011: in a chat (the default subject) only the icon shows; the word stays in the tooltip and for screen readers. -->
        <span v-if="pending || subject !== 'new messages'" aria-hidden="true" class="hidden sm:inline">{{ pending ? 'Update waiting' : word }}</span>
        <span class="sr-only">{{ label }}</span>
    </span>
</template>
