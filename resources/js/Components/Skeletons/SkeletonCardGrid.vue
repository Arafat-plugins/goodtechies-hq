<script setup lang="ts">
import { computed } from 'vue';
import { Skeleton } from '@/Components/ui/skeleton';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        cards?: number;
        /** Columns at the widest breakpoint; always one column at 375. */
        columns?: 1 | 2 | 3 | 4;
    }>(),
    { cards: 6, columns: 3 },
);

/** Written out in full so Tailwind keeps every one of these classes in the build. */
const COLUMN_CLASS: Record<1 | 2 | 3 | 4, string> = {
    1: 'grid-cols-1',
    2: 'grid-cols-1 md:grid-cols-2',
    3: 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3',
    4: 'grid-cols-1 md:grid-cols-2 xl:grid-cols-4',
};

const cardCount = computed(() => Array.from({ length: props.cards }, (_, index) => index));
const gridClass = computed(() => COLUMN_CLASS[props.columns]);
</script>

<template>
    <div :class="cn('grid min-w-0 gap-4', gridClass)" aria-busy="true" aria-live="polite">
        <span class="sr-only">Loading</span>
        <div
            v-for="card in cardCount"
            :key="`card-${card}`"
            class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
        >
            <div class="flex items-center justify-between gap-2">
                <Skeleton class="bg-muted-foreground/20 h-4 w-1/2" />
                <Skeleton class="bg-muted-foreground/20 h-5 w-16 rounded-full" />
            </div>
            <Skeleton class="bg-muted-foreground/20 h-3 w-2/3" />
            <Skeleton class="bg-muted-foreground/20 h-3 w-1/3" />
        </div>
    </div>
</template>
