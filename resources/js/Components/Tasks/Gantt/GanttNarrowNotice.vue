<script setup lang="ts">
import { Info, X } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/Components/ui/button';
import { queryParam, syncQuery } from '@/lib/tableState';

/**
 * Why somebody on a narrow screen is looking at the List when they asked for the Gantt.
 *
 * "On phone width it degrades to the List view with a notice (Gantt is desktop-only by
 * design)" — the degrading is `TaskGantt.vue`'s, and this is the notice, on the other side of
 * the navigation. Without it the Gantt tab simply appears not to work.
 *
 * `?from=gantt` is a one-shot, read in setup and taken straight back out of the address bar,
 * exactly as `?new=1` is on this page. Two reasons: a refresh should not keep re-explaining
 * itself, and a parameter left in the query string would be merged into every `pushQuery` the
 * filter chips make and follow the reader around for the rest of the session.
 */

const showing = ref(queryParam('from') === 'gantt');

if (showing.value) {
    syncQuery({ from: null });
}
</script>

<template>
    <div v-if="showing" class="flex items-start gap-2 rounded-md border bg-muted p-3 text-sm">
        <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <p class="min-w-0 flex-1">
            A Gantt needs a wider screen, so this is the List view instead — the filters you set came with you.
        </p>
        <Button type="button" variant="ghost" size="icon-sm" aria-label="Dismiss this notice" @click="showing = false">
            <X aria-hidden="true" />
        </Button>
    </div>
</template>
