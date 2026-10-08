<script setup lang="ts">
import { Circle, CircleCheck, CircleDashed, CirclePause, CirclePlay, CircleX, Eye, RotateCcw } from '@lucide/vue';
import { computed, type Component } from 'vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import { labelFor } from '@/Components/StatusBadge.vue';
import { cn } from '@/lib/utils';

/**
 * Polish 038: a status as its word and a small icon — no capsule, no dot.
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

/**
 * Polish 039: the client's reference — a split tag. Left: the word on the solid status colour;
 * a slanted cut; right: the icon in that colour on the card. Polish 040: no heavier bottom edge,
 * medium weight. `-fg` is the solid side because it
 * meets 6:1 against `--background` in both themes (dark text on the light fg in dark mode, light
 * text on the dark fg in light mode). Written out in full so Tailwind keeps every class.
 */
const TONE: Record<StatusKey, { box: string; word: string; icon: string }> = {
    backlog: { box: 'border-status-backlog-border', word: 'bg-status-backlog-fg', icon: 'text-status-backlog-fg' },
    todo: { box: 'border-status-todo-border', word: 'bg-status-todo-fg', icon: 'text-status-todo-fg' },
    progress: { box: 'border-status-progress-border', word: 'bg-status-progress-fg', icon: 'text-status-progress-fg' },
    review: { box: 'border-status-review-border', word: 'bg-status-review-fg', icon: 'text-status-review-fg' },
    changes: { box: 'border-status-changes-border', word: 'bg-status-changes-fg', icon: 'text-status-changes-fg' },
    done: { box: 'border-status-done-border', word: 'bg-status-done-fg', icon: 'text-status-done-fg' },
    waiting: { box: 'border-status-waiting-border', word: 'bg-status-waiting-fg', icon: 'text-status-waiting-fg' },
    cancelled: { box: 'border-status-cancelled-border', word: 'bg-status-cancelled-fg', icon: 'text-status-cancelled-fg' },
};

/** The slanted edge of the word side; the icon side runs underneath it. */
const SLANT = 'polygon(0 0, 100% 0, calc(100% - 0.5rem) 100%, 0 100%)';

const tone = computed(() => TONE[props.status] ?? TONE.todo);
</script>

<template>
    <span :class="cn('inline-flex h-6 shrink-0 items-stretch overflow-hidden rounded-md border bg-card text-xs font-medium tracking-wide uppercase', tone.box)">
        <span class="relative z-10 flex items-center pr-3.5 pl-2 text-background" :class="tone.word" :style="{ clipPath: SLANT }">
            {{ props.label ?? labelFor(props.status) }}
        </span>
        <span :class="cn('-ml-2 flex items-center pr-1.5 pl-2.5', tone.icon)">
            <component :is="ICON[props.status] ?? Circle" class="size-3.5" aria-hidden="true" />
        </span>
    </span>
</template>
