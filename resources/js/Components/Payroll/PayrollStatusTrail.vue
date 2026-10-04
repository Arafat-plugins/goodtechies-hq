<script setup lang="ts">
import { Check, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { PayrollStatusStep, PayrollStatusValue } from '@/Components/Payroll/payroll';

/**
 * **Where the month is, and what comes next** — the state machine, drawn.
 *
 * `DRAFT → CALCULATED → REVIEWED → APPROVED → LOCKED → PAID`, which is Part D §14's arrow
 * verbatim and `PayrollStatus::TRANSITIONS`'s forward path. The six rungs arrive from the
 * **server**, with their labels and their `StatusBadge` tones already resolved (decision 2-37),
 * so nothing in Vue holds a second copy of the map — a screen that listed the statuses itself
 * would be the copy that missed the seventh.
 *
 * ## Colour is never the only carrier
 *
 * DESIGN.md §5 rule 6, and §1.4 gives the measurement that makes it non-negotiable: two of the
 * eight status tones sit ΔE 0.16 apart under deuteranopia, so a tinted chip is not a state. So
 * every rung prints its **label**, the one the month is on is the only `StatusBadge` and also
 * carries `aria-current="step"`, and each rung has an `sr-only` word saying whether it is done,
 * where the month is now, or still ahead. Read with the styles off, this is an ordered list of
 * six labelled steps with one of them marked *now*.
 *
 * ## It wraps rather than scrolls
 *
 * Six rungs across is wider than 360 px, so the list is a wrapping flex rather than a row — at
 * a phone width it becomes two or three short lines, and the page never scrolls sideways. The
 * chevrons between rungs are `aria-hidden`: the `<ol>` already says these are in order.
 *
 * **The one backward move is not drawn here.** A lock reversal takes `locked → approved`, and
 * putting an arrow back up the ladder would suggest it is part of the ordinary path, which it
 * is not — it is an Admin reopening a closed month, and the screen says that where the control
 * is. What this component does show, after a reversal, is that the month is on `Approved`
 * again, which is the true statement.
 */
const props = defineProps<{
    /** The six statuses in order, from the server. */
    statuses: PayrollStatusStep[];
    /** Where this month actually is. */
    current: PayrollStatusValue | null;
    /** "September 2026" — the list's accessible name, for a reader arriving out of context. */
    monthLabel: string;
}>();

// Polish 002: Locked is no longer on the spine; a month still sitting in it reads as Approved.
const currentIndex = computed(() =>
    props.statuses.findIndex((step) => step.value === (props.current === 'locked' ? 'approved' : props.current)),
);

/** 'done' — already passed · 'current' — where the month is · 'ahead' — not yet. */
function positionOf(index: number): 'done' | 'current' | 'ahead' {
    if (currentIndex.value < 0) {
        return 'ahead';
    }

    if (index < currentIndex.value) {
        return 'done';
    }

    return index === currentIndex.value ? 'current' : 'ahead';
}

const POSITION_WORDS: Record<'done' | 'current' | 'ahead', string> = {
    done: 'passed',
    current: 'where this month is now',
    ahead: 'still ahead',
};
</script>

<template>
    <nav :aria-label="`Payroll progress for ${monthLabel}`">
        <ol class="flex min-w-0 list-none flex-wrap items-center gap-x-2 gap-y-2">
            <li
                v-for="(step, index) in statuses"
                :key="step.value"
                class="flex items-center gap-2"
                :aria-current="positionOf(index) === 'current' ? 'step' : undefined"
            >
                <StatusBadge
                    v-if="positionOf(index) === 'current'"
                    :status="step.state"
                    :label="step.label"
                    size="sm"
                />
                <span
                    v-else
                    class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs"
                    :class="positionOf(index) === 'done' ? 'text-foreground' : 'text-muted-foreground'"
                >
                    <Check v-if="positionOf(index) === 'done'" class="size-3" aria-hidden="true" />
                    <span
                        v-else
                        class="size-2 rounded-full bg-muted-foreground/60"
                        aria-hidden="true"
                    />
                    {{ step.label }}
                </span>

                <span class="sr-only">— {{ POSITION_WORDS[positionOf(index)] }}.</span>

                <ChevronRight
                    v-if="index < statuses.length - 1"
                    class="size-3 text-muted-foreground"
                    aria-hidden="true"
                />
            </li>
        </ol>
    </nav>
</template>
