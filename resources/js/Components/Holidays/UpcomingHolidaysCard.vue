<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PartyPopper } from '@lucide/vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { Holiday } from '@/Components/Holidays/holidays';
import { formatHolidayDayMonth, holidayWhen } from '@/Components/Holidays/holidays';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';

/**
 * "Upcoming holidays" — the card Part D §3 puts on both dashboards.
 *
 * One component, both surfaces, for the reason `Components/Tasks/MyTasks.vue` is one component
 * on both: it is the same question with the same answer, and two copies would be two places for
 * "is today a holiday" to be worded differently.
 *
 * ## What it says, and what it never says
 *
 * Each row prints the date, the weekday, the name and **how far off it is in words** — "Today",
 * "Tomorrow", "In 12 days". The words are the point rather than a flourish: a card that only
 * emphasised the nearest row would say nothing in greyscale and nothing to a screen reader
 * (DESIGN.md §6 rule 6), and "Today" is the single most useful thing this card can print.
 *
 * Nothing here is computed from a `Date`: the weekday and the day count come from the server,
 * whose timezone is the agency's. A browser set to another zone would otherwise move a holiday
 * by a day on somebody's laptop and nowhere else.
 *
 * **Zero is an answer.** An empty card says the calendar is clear to the end of the year, not
 * "nothing to show" — and on the admin surface it offers the way to add one, because the
 * likeliest reason the list is empty in January is that nobody has entered the new year's
 * gazette yet.
 */
withDefaults(
    defineProps<{
        holidays: Holiday[];
        /** Where "See all" goes, when the viewer has a screen to go to. Omit on the employee shell. */
        manageHref?: string | null;
    }>(),
    { manageHref: null },
);
</script>

<template>
    <Card class="min-w-0 gap-4 p-6 shadow-xs">
        <div class="flex min-w-0 items-center justify-between gap-4">
            <h2 class="text-sm font-medium">Upcoming holidays</h2>
            <Link
                v-if="manageHref && holidays.length > 0"
                :href="manageHref"
                class="shrink-0 text-xs text-muted-foreground underline-offset-4 hover:underline"
            >
                See all
            </Link>
        </div>

        <EmptyState
            v-if="holidays.length === 0"
            :icon="PartyPopper"
            title="Nothing on the calendar"
            description="No company holiday is coming up. Bangladesh gazettes its list one year at a time, so next year's appears once it has been entered."
        >
            <template v-if="manageHref" #action>
                <Button as-child size="sm" variant="outline">
                    <Link :href="manageHref">Open the holiday calendar</Link>
                </Button>
            </template>
        </EmptyState>

        <ul v-else class="flex min-w-0 flex-col divide-y">
            <li
                v-for="holiday in holidays"
                :key="holiday.id"
                class="flex min-w-0 flex-col gap-0.5 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4"
            >
                <div class="flex min-w-0 flex-col gap-0.5">
                    <p class="truncate text-sm font-medium">{{ holiday.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        <span class="tabular-nums">{{ formatHolidayDayMonth(holiday.date) }}</span>
                        · {{ holiday.weekday }}
                    </p>
                </div>
                <!--
                    The second encoding, in words. `font-medium` on today rather than a colour
                    alone — the word already says "Today", and the weight is the emphasis.
                -->
                <span
                    class="shrink-0 text-xs"
                    :class="holiday.is_today ? 'font-medium text-foreground' : 'text-muted-foreground'"
                >
                    {{ holidayWhen(holiday) }}
                </span>
            </li>
        </ul>
    </Card>
</template>
