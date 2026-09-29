<script setup lang="ts">
import { Flag } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A task's priority as a filled flag plus its word: Urgent red, High orange, Medium blue, Low
 * grey — the `--priority-*` tokens (DESIGN.md §1.4b), each ≥ 3:1 on `--card` in both themes.
 *
 * The flag is a **mark** and keeps its colour; the word beside it is plain `text-muted-foreground`
 * text, because a priority token is never a text colour (§1.4b). The word carries the meaning
 * (§5.6), so the flag is `aria-hidden` and the accessible text is the visible word plus a
 * screen-reader-only " priority" — read once, never twice. The four keys are
 * `App\Support\TaskPriority`'s values; anything else, or none, renders nothing.
 *
 * `relative` is load-bearing: `sr-only` is `position: absolute`, and without a positioned
 * ancestor inside the Board's sideways scroller it escapes that clip and widens the whole page.
 */

const props = defineProps<{
    priority: string | null | undefined;
}>();

const FLAGS: Record<string, { word: string; tone: string }> = {
    urgent: { word: 'Urgent', tone: 'text-priority-urgent fill-priority-urgent' },
    high: { word: 'High', tone: 'text-priority-high fill-priority-high' },
    medium: { word: 'Medium', tone: 'text-priority-medium fill-priority-medium' },
    low: { word: 'Low', tone: 'text-priority-low fill-priority-low' },
};

const flag = computed(() => (props.priority ? (FLAGS[props.priority] ?? null) : null));
</script>

<template>
    <span
        v-if="flag"
        data-priority-flag
        :data-priority="priority"
        class="relative inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
    >
        <Flag :class="cn('size-4 shrink-0', flag.tone)" aria-hidden="true" />
        <span>{{ flag.word }}<span class="sr-only"> priority</span></span>
    </span>
</template>
