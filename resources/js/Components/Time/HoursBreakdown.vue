<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { TimeBreakdownRow } from '@/Components/Time/time';
import { formatDuration } from '@/Components/Timer/timer';
import { Card } from '@/Components/ui/card';

/**
 * Hours today and this week against one thing — an employee, a project or a task.
 *
 * ## Why this is three small tables and not a `DataTable`
 *
 * Each is at most twenty-five rows of a name and three numbers, read at a glance beside the two
 * others. `DataTable` would have brought a column menu, a density toggle and sortable headers
 * the controller does not implement, and DESIGN.md §5.11 forbids a control the server ignores.
 * Below `md` the numbers wrap under the name rather than becoming a card list of three figures.
 *
 * ## Pending is beside the total, never inside it
 *
 * Decision 4-7: a total asks `approved_at is not null` and nothing else. Hours waiting for a
 * sign-off get their own words on their own line — visible, because an employee's afternoon
 * that is simply absent from the Admin's screen is one nobody will ever sign off, and not
 * counted, because it has not been agreed.
 *
 * ## Nothing here is a rating
 *
 * Employees arrive sorted by name and projects and tasks by hours, which is the caller's doing
 * and is deliberate: ranking WORK by time spent is a workload question, ranking PEOPLE by it
 * would be a league table (Part H §1). No row carries a percentage, a target or a comparison.
 */

defineProps<{
    title: string;
    /** What one row is, for the empty state's sentence. */
    noun: string;
    icon: Component;
    rows: TimeBreakdownRow[];
}>();
</script>

<template>
    <Card class="flex min-w-0 flex-col gap-4 p-6">
        <h3 class="text-sm font-medium">{{ title }}</h3>

        <EmptyState
            v-if="!rows.length"
            :icon="icon"
            title="Nothing tracked this week"
            :description="`No time has been recorded against any ${noun} in this week.`"
        />

        <ul v-else class="flex min-w-0 flex-col divide-y">
            <li
                v-for="row in rows"
                :key="row.id"
                class="flex min-w-0 flex-col gap-1 py-2 first:pt-0 last:pb-0 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4"
            >
                <div class="flex min-w-0 flex-col">
                    <Link :href="row.href" class="truncate text-sm underline-offset-4 hover:underline">
                        {{ row.name }}
                    </Link>
                    <span v-if="row.meta" class="truncate text-xs text-muted-foreground">{{ row.meta }}</span>
                </div>

                <div class="flex shrink-0 flex-col items-start gap-0.5 sm:items-end">
                    <span class="text-sm tabular-nums">
                        <span class="text-muted-foreground">Today</span> {{ formatDuration(row.today_seconds) }}
                        <span class="text-muted-foreground"> · This week</span>
                        {{ formatDuration(row.week_seconds) }}
                    </span>
                    <!--
                        Only when there is something waiting. A line reading "0m waiting" on
                        every row would bury the one row where it matters.
                    -->
                    <span v-if="row.pending_week_seconds > 0" class="text-xs tabular-nums text-status-waiting-fg">
                        {{ formatDuration(row.pending_week_seconds) }} waiting for approval — not counted
                    </span>
                </div>
            </li>
        </ul>
    </Card>
</template>
