<script setup lang="ts">
import { Circle, CircleCheck, CircleDashed, CirclePause, CirclePlay, CircleX, Eye, RotateCcw } from '@lucide/vue';
import type { Component } from 'vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import { labelFor } from '@/Components/StatusBadge.vue';
import { cn } from '@/lib/utils';

/**
 * Polish 038: a status as a small icon and its word in the status colour — no capsule, no dot.
 * Used where a row is already a card and a tinted pill inside it reads as clutter (Admin →
 * Projects by client). The colour never carries the meaning alone: the word is always there.
 */
const props = defineProps<{
    status: StatusKey;
    label?: string;
}>();

const ICON: Record<StatusKey, Component> = {
    backlog: CircleDashed,
    todo: Circle,
    progress: CirclePlay,
    review: Eye,
    changes: RotateCcw,
    done: CircleCheck,
    waiting: CirclePause,
    cancelled: CircleX,
};

/** Written out in full so Tailwind keeps every class. */
const TEXT: Record<StatusKey, string> = {
    backlog: 'text-status-backlog-fg',
    todo: 'text-status-todo-fg',
    progress: 'text-status-progress-fg',
    review: 'text-status-review-fg',
    changes: 'text-status-changes-fg',
    done: 'text-status-done-fg',
    waiting: 'text-status-waiting-fg',
    cancelled: 'text-status-cancelled-fg',
};
</script>

<template>
    <span :class="cn('inline-flex shrink-0 items-center gap-1 text-xs font-medium', TEXT[props.status] ?? 'text-muted-foreground')">
        <component :is="ICON[props.status] ?? Circle" class="size-3.5" aria-hidden="true" />
        {{ props.label ?? labelFor(props.status) }}
    </span>
</template>
