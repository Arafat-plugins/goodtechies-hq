<script setup lang="ts">
/**
 * Polish 049/051: To do · In progress · Complete as three small coloured pills, each with its
 * count AND its name written out, so nobody has to guess which number is which.
 */
import { computed } from 'vue';
import type { TaskCounts } from '@/Components/Clients/clientTasks';
import { statusToneClass } from '@/Components/StatusBadge.vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        counts: TaskCounts;
        /** Polish 053: the three pills overlap like a stacked group (Clients list). */
        stacked?: boolean;
    }>(),
    { stacked: false },
);

const chips = computed(() => [
    { key: 'todo', label: 'To do', count: props.counts.todo, tone: statusToneClass('todo') },
    { key: 'progress', label: 'In progress', count: props.counts.progress, tone: statusToneClass('progress') },
    { key: 'complete', label: 'Complete', count: props.counts.complete, tone: statusToneClass('done') },
]);
</script>

<template>
    <span :class="cn('flex items-center', stacked ? '' : 'flex-wrap gap-1')" :data-stacked="stacked || undefined">
        <span
            v-for="(chip, index) in chips"
            :key="chip.key"
            :data-count="chip.key"
            :class="
                cn(
                    'inline-flex items-center gap-1 rounded-full border text-xs whitespace-nowrap',
                    chip.tone,
                    stacked
                        ? cn('relative h-6 border-2 border-card pr-2.5', index === 0 ? 'pl-2.5' : '-ml-2.5 pl-4')
                        : 'h-5 px-2',
                )
            "
            :style="stacked ? { zIndex: chips.length - index } : undefined"
        >
            <span class="font-semibold tabular-nums">{{ chip.count }}</span>
            <span>{{ chip.label }}</span>
        </span>
    </span>
</template>
