<script lang="ts">
/** The eight status colours DESIGN.md defines. Nothing outside this list is a valid status. */
export type StatusKey =
    | 'backlog'
    | 'todo'
    | 'progress'
    | 'review'
    | 'changes'
    | 'done'
    | 'waiting'
    | 'cancelled';

/** Default human label per status. A `label` prop overrides it. */
const STATUS_LABELS: Record<StatusKey, string> = {
    backlog: 'Backlog',
    todo: 'To do',
    progress: 'In progress',
    review: 'In review',
    changes: 'Changes requested',
    done: 'Done',
    waiting: 'Waiting',
    cancelled: 'Cancelled',
};

export function labelFor(status: StatusKey): string {
    return STATUS_LABELS[status];
}

/**
 * Written out in full so Tailwind keeps every one of these classes in the build.
 * Each status carries a foreground / background / border triplet, so a status-toned
 * surface reads the same in light and dark without a single conditional.
 */
const TONE_CLASS: Record<StatusKey, string> = {
    backlog: 'bg-status-backlog-bg text-status-backlog-fg border-status-backlog-border',
    todo: 'bg-status-todo-bg text-status-todo-fg border-status-todo-border',
    progress: 'bg-status-progress-bg text-status-progress-fg border-status-progress-border',
    review: 'bg-status-review-bg text-status-review-fg border-status-review-border',
    changes: 'bg-status-changes-bg text-status-changes-fg border-status-changes-border',
    done: 'bg-status-done-bg text-status-done-fg border-status-done-border',
    waiting: 'bg-status-waiting-bg text-status-waiting-fg border-status-waiting-border',
    cancelled: 'bg-status-cancelled-bg text-status-cancelled-fg border-status-cancelled-border',
};

/**
 * The status triplet for a surface that is not this badge.
 *
 * The Calendar's span bars need it: a bar is not a pill, so it cannot *be* a `StatusBadge`,
 * but it must not carry a second copy of the mapping either. It still prints its label —
 * a tinted rectangle with no words is colour carrying meaning alone (DESIGN.md §5.6).
 */
export function statusToneClass(status: StatusKey): string {
    return TONE_CLASS[status];
}
</script>

<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        /** The status *is* the colour — there is deliberately no colour prop. */
        status: StatusKey;
        /** Overrides the default label for this status. */
        label?: string;
        size?: 'sm' | 'md';
    }>(),
    { size: 'md' },
);

/** The dot keeps the saturated base colour, so it stays readable on the tinted pill. */
const DOT_CLASS: Record<StatusKey, string> = {
    backlog: 'bg-status-backlog',
    todo: 'bg-status-todo',
    progress: 'bg-status-progress',
    review: 'bg-status-review',
    changes: 'bg-status-changes',
    done: 'bg-status-done',
    waiting: 'bg-status-waiting',
    cancelled: 'bg-status-cancelled',
};

const SIZE_CLASS: Record<'sm' | 'md', string> = {
    sm: 'gap-1 px-1.5 py-0',
    md: 'gap-1.5 px-2 py-0.5',
};

const badgeClass = computed(() => cn(TONE_CLASS[props.status], SIZE_CLASS[props.size]));
const dotClass = computed(() => DOT_CLASS[props.status]);
const text = computed(() => props.label ?? labelFor(props.status));
</script>

<template>
    <span
        :class="
            cn('inline-flex items-center rounded-full border text-xs font-medium whitespace-nowrap', badgeClass)
        "
    >
        <span :class="cn('size-1.5 shrink-0 rounded-full', dotClass)" aria-hidden="true" />
        {{ text }}
    </span>
</template>
