<script lang="ts">
import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The six status colours DESIGN.md defines. Nothing outside this list is a valid tone.
 * Kept as an alias of `StatusKey` so the two names cannot drift apart.
 */
export type StatusTone = StatusKey;

/**
 * Project status → pill tone. Kept here so every surface that shows a project status
 * colours it the same way.
 */
export function toneForProjectStatus(status: string): StatusTone {
    switch (status) {
        case 'active':
            return 'progress';
        case 'on_hold':
            return 'waiting';
        case 'completed':
            return 'done';
        case 'cancelled':
            return 'cancelled';
        case 'archived':
            return 'todo';
        default:
            return 'todo';
    }
}
</script>

<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';

/**
 * Superseded by `StatusBadge`: this is now a thin alias that keeps the
 * `label` / `tone` props the Phase 1 screens already pass.
 */
defineProps<{
    label: string;
    tone: StatusTone;
}>();
</script>

<template>
    <StatusBadge :status="tone" :label="label" />
</template>
