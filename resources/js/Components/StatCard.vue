<script lang="ts">
/**
 * A change against an earlier period. The direction is the meaning; `value` carries the
 * magnitude only (`3`, `12%`), so the component owns the sign and never prints "+-3".
 */
export interface StatDelta {
    value: string | number;
    direction: 'up' | 'down' | 'flat';
    /** What the change is measured against. */
    since?: string;
}
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Minus, TrendingDown, TrendingUp } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        label: string;
        /** Real value. Omit it and set `phase` for a placeholder card. */
        value?: string | number;
        /** Sub-line; a placeholder defaults to "Arrives in Phase N". */
        sub?: string;
        phase?: number;
        icon?: Component;
        /**
         * Only ever passed when it can be computed from real data. There is no
         * "unknown" delta — a card with nothing to compare against simply has none.
         */
        delta?: StatDelta;
        /** Makes the whole card one link: whole hit area, one tab stop. */
        href?: string;
        /**
         * `compact` is the same card at a lower visual weight, for a secondary tier that
         * must not compete with the hero row. It only changes the size of the number.
         */
        size?: 'default' | 'compact';
    }>(),
    { size: 'default' },
);

const isPlaceholder = computed(() => props.value === undefined);
const subline = computed(() => props.sub ?? (props.phase ? `Arrives in Phase ${props.phase}` : ''));

/** An icon as well as the colour, so direction never rests on hue alone. */
const DELTA_ICON: Record<StatDelta['direction'], Component> = {
    up: TrendingUp,
    down: TrendingDown,
    flat: Minus,
};

/**
 * `--status-done` / `--status-cancelled` in their text-safe step (`-fg`): the same two
 * tokens the status badges use, at the lightness that clears 5.7:1 on a card.
 */
const DELTA_CLASS: Record<StatDelta['direction'], string> = {
    up: 'text-status-done-fg',
    down: 'text-status-cancelled-fg',
    flat: 'text-muted-foreground',
};

const DELTA_SIGN: Record<StatDelta['direction'], string> = { up: '+', down: '−', flat: '' };

/** Spoken in words — the arrow and the colour are decoration on top of this. */
const deltaLabel = computed(() => {
    if (!props.delta) {
        return '';
    }

    const since = props.delta.since ?? 'last week';
    if (props.delta.direction === 'flat') {
        return `no change versus ${since}`;
    }

    return `${props.delta.direction} ${props.delta.value} versus ${since}`;
});
</script>

<template>
    <!-- A card with an `href` is the link itself, so there is one tab stop and no nested anchor. -->
    <component
        :is="href ? Link : 'div'"
        :href="href"
        :class="cn('block min-w-0', href && 'rounded-xl outline-none focus-visible:ring-3 focus-visible:ring-ring/50')"
    >
        <Card :class="cn('h-full min-w-0 gap-2 p-4 shadow-xs', href && 'transition-colors hover:bg-accent/40')">
            <div class="flex items-center justify-between gap-2">
                <p class="truncate text-sm text-muted-foreground">{{ label }}</p>
                <component :is="icon" v-if="icon" class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            </div>

            <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                <p
                    :class="
                        cn(
                            'font-semibold tabular-nums',
                            size === 'compact' ? 'text-xl' : 'text-3xl',
                            isPlaceholder && 'text-muted-foreground',
                        )
                    "
                >
                    <template v-if="isPlaceholder">
                        <span aria-hidden="true">—</span>
                        <span class="sr-only">Not available yet</span>
                    </template>
                    <template v-else>{{ value }}</template>
                </p>
                <p
                    v-if="delta"
                    :class="cn('flex items-center gap-1 text-xs font-medium', DELTA_CLASS[delta.direction])"
                >
                    <component :is="DELTA_ICON[delta.direction]" class="size-3.5 shrink-0" aria-hidden="true" />
                    <span aria-hidden="true">{{ DELTA_SIGN[delta.direction] }}{{ delta.value }}</span>
                    <span class="sr-only">{{ deltaLabel }}</span>
                </p>
            </div>

            <p v-if="subline" class="text-xs text-muted-foreground">{{ subline }}</p>

            <!-- Left unfilled until a series exists; an empty slot renders nothing at all. -->
            <div v-if="$slots.sparkline" class="min-w-0">
                <slot name="sparkline" />
            </div>
        </Card>
    </component>
</template>
