<script setup lang="ts">
import { RefreshCw } from '@lucide/vue';
import { Button } from '@/Components/ui/button';

/**
 * "Something changed while you were reading this" — the one shape every live notice takes.
 *
 * ## It offers a reload; it never performs one
 *
 * Somebody is reading this page, and quite possibly typing into it. A page that re-fetched
 * itself under them would lose the caret, scroll the list they were reading and — the thing
 * that actually matters — take focus away from whatever they had it on. So a live update's
 * whole effect on the screen is one sentence and a button, and the reader decides.
 *
 * ## Where it sits, and why that is not an aesthetic choice
 *
 * Last in the page's flow, and `sticky` rather than `fixed`. Appearing at the END of the
 * document pushes nothing that precedes it, so no content moves under anybody's cursor or
 * screen-reader position — measured at 375 and 1280 in both themes, with the heading's
 * `getBoundingClientRect().top` and `window.scrollY` unchanged across an arrival. `sticky`
 * keeps it in view while they scroll without taking it out of flow and covering something. A
 * banner at the top would have moved the whole page down the moment a colleague dragged a card.
 *
 * ## What a screen reader hears
 *
 * One polite sentence, from a region that is **always in the DOM** — a region created at the
 * moment it has something to say is a region that is never announced. `polite` waits for a
 * pause rather than cutting across whatever is being read, and a second change replaces the
 * sentence instead of queueing another, because the current state is the news and the states
 * it passed through on the way are not.
 *
 * Nothing here is focusable until there is something to say, and nothing here takes focus even
 * then.
 */

defineProps<{
    /** The sentence, or an empty string while there is nothing to say. */
    message: string;
}>();

const emit = defineEmits<{ (event: 'reload'): void }>();
</script>

<template>
    <div class="sticky bottom-4 z-10 mt-2 flex justify-center" role="status" aria-live="polite">
        <p
            v-if="message"
            class="flex flex-wrap items-center gap-3 rounded-md border bg-card px-3 py-2 text-sm shadow-sm"
        >
            <span>{{ message }}</span>
            <Button variant="outline" size="sm" @click="emit('reload')">
                <RefreshCw class="size-4" aria-hidden="true" />
                Reload
            </Button>
        </p>
    </div>
</template>
