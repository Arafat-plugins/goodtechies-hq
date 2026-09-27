<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import type { FinanceMonth } from '@/Components/Finance/finance';
import { formatMonthLabel } from '@/Components/Finance/finance';
import { Button } from '@/Components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';

/**
 * The month a ledger is on, and the two steps either side of it.
 *
 * **Real links, not buttons**, because the month is in the URL (`?month=YYYY-MM`): a month is
 * something you bookmark, send to somebody and reach with the back button, and an anchor is
 * what gives a keyboard and a screen reader all three for free. Same shape as the Meetings
 * calendar's arrows and the Tasks view switcher.
 *
 * The two arrows are icon-only, so each carries both an `aria-label` naming the month it goes
 * to and a tooltip printing the same words — an icon control that only a mouse can identify is
 * not one (`AGENTS.md`'s accessibility floor). The current month is text between them, so the
 * state is never carried by which arrow looks pressed.
 *
 * `href` is passed in rather than hard-coded, because the same control sits on the income
 * ledger, the expense ledger, the dashboard and the report, and each of those is a different
 * path with the same parameter.
 */
const props = defineProps<{
    month: FinanceMonth;
    /** `(month: 'YYYY-MM') => string` — the screen's own URL with that month on it. */
    href: (month: string) => string;
}>();

const previousLabel = computed(() => formatMonthLabel(props.month.previous));
const nextLabel = computed(() => formatMonthLabel(props.month.next));
</script>

<template>
    <TooltipProvider>
        <nav class="flex min-w-0 items-center gap-1" aria-label="Month">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button as-child variant="outline" size="icon">
                        <Link :href="href(month.previous)" :aria-label="`Go to ${previousLabel}`">
                            <ChevronLeft aria-hidden="true" />
                        </Link>
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ previousLabel }}</TooltipContent>
            </Tooltip>

            <!--
                The month itself. `aria-current="page"` rather than a tinted pill: this IS the
                page, and the word is the whole of the state (DESIGN.md §5 rule 6).
            -->
            <p class="min-w-0 flex-1 px-2 text-center text-sm font-medium tabular-nums" aria-current="page">
                {{ month.label }}
            </p>

            <Tooltip>
                <TooltipTrigger as-child>
                    <Button as-child variant="outline" size="icon">
                        <Link :href="href(month.next)" :aria-label="`Go to ${nextLabel}`">
                            <ChevronRight aria-hidden="true" />
                        </Link>
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ nextLabel }}</TooltipContent>
            </Tooltip>
        </nav>
    </TooltipProvider>
</template>
