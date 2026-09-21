<script setup lang="ts">
import { computed } from 'vue';
import { Skeleton } from '@/Components/ui/skeleton';

const props = withDefaults(
    defineProps<{
        rows?: number;
        columns?: number;
    }>(),
    { rows: 6, columns: 4 },
);

/** Equal flex cells instead of a generated grid class, so nothing is an arbitrary value. */
const columnCount = computed(() => Array.from({ length: props.columns }, (_, index) => index));
const rowCount = computed(() => Array.from({ length: props.rows }, (_, index) => index));
</script>

<template>
    <div class="overflow-hidden rounded-xl border bg-card shadow-xs" aria-busy="true" aria-live="polite">
        <span class="sr-only">Loading</span>
        <!-- Header row: shorter bars, matching the real `text-xs uppercase` header. -->
        <div class="flex items-center gap-4 border-b bg-muted/40 px-4 py-3">
            <Skeleton v-for="column in columnCount" :key="`head-${column}`" class="bg-muted-foreground/20 h-3 flex-1" />
        </div>
        <div
            v-for="row in rowCount"
            :key="`row-${row}`"
            class="flex items-center gap-4 px-4 py-3.5 even:bg-muted/40"
        >
            <Skeleton v-for="column in columnCount" :key="`cell-${row}-${column}`" class="bg-muted-foreground/20 h-4 flex-1" />
        </div>
    </div>
</template>
