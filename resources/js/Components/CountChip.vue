<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import { statusToneClass } from '@/Components/StatusBadge.vue';
import { cn } from '@/lib/utils';

/**
 * A status count: icon in a tinted circle, the label, the number in a tinted bubble (polish 015).
 * The colour still comes only from the status — the triplet `StatusBadge` owns — and the label
 * is always printed, so colour never carries the meaning alone (DESIGN.md §5.6).
 */
const props = defineProps<{
    status: StatusKey;
    label: string;
    count: number;
    icon?: Component;
}>();

/** The icon circle and the count bubble: the saturated base colour at a low tint. */
const TINT_CLASS: Record<StatusKey, string> = {
    backlog: 'bg-status-backlog/15',
    todo: 'bg-status-todo/15',
    progress: 'bg-status-progress/15',
    review: 'bg-status-review/15',
    changes: 'bg-status-changes/15',
    done: 'bg-status-done/15',
    waiting: 'bg-status-waiting/15',
    cancelled: 'bg-status-cancelled/15',
};

const tint = computed(() => TINT_CLASS[props.status]);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex h-9 items-center gap-2 rounded-full border p-1 shadow-raised',
                icon ? 'pl-1' : 'pl-3',
                statusToneClass(status),
            )
        "
    >
        <span v-if="icon" :class="cn('flex size-7 shrink-0 items-center justify-center rounded-full', tint)">
            <component :is="icon" class="size-4" aria-hidden="true" />
        </span>
        <span class="text-sm font-medium whitespace-nowrap text-foreground">{{ label }}</span>
        <span
            :class="
                cn(
                    'flex h-7 min-w-7 shrink-0 items-center justify-center rounded-full px-2 text-sm font-semibold tabular-nums',
                    tint,
                )
            "
        >
            <span class="sr-only">:</span>{{ count }}
        </span>
    </span>
</template>
