<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Inbox, ListChecks, Scale } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import LeaveCalendar from '@/Components/Leave/LeaveCalendar.vue';
import type { LeaveCalendarDay, LeaveMonth, LeaveRequestRow } from '@/Components/Leave/leave';
import { leaveRoutes } from '@/Components/Leave/leave';
import LeaveRequestCard from '@/Components/Leave/LeaveRequestCard.vue';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/**
 * Admin → Workforce → Leave → Calendar: who is away this month.
 *
 * ## Approved and pending, and never the same colour
 *
 * An Admin looking at this to decide whether they can spare somebody next week needs to see
 * what has already been asked for as well as what has been granted — so both are drawn, each
 * with its own tone AND its own word (`LeaveStatus::tone()` and `label()`). DESIGN.md §5.6, and
 * §1.4's measurement is why the word is not optional.
 *
 * ## The month is in the URL
 *
 * `?month=YYYY-MM`, so a month is a link somebody can send (DESIGN.md §5.10) — the same shape
 * the attendance month page uses.
 *
 * ## What it becomes at 360 px
 *
 * The grid is `LeaveCalendar`'s business and it becomes a list below `md`. Its docblock carries
 * the measurement: a leave cell has to fit a name and a status word, which is strictly more
 * than the attendance cell decision 4-13 already measured as not fitting at 48 px.
 */

const props = defineProps<{
    month: LeaveMonth;
    days: LeaveCalendarDay[];
    requests: LeaveRequestRow[];
}>();

/** How many distinct people are away at some point this month. A count, never a rating. */
const peopleAway = computed(() => {
    const ids = new Set<number>();

    for (const day of props.days) {
        for (const person of day.people) {
            ids.add(person.employee_id);
        }
    }

    return ids.size;
});
</script>

<template>
    <Head :title="`Leave calendar — ${month.label}`" />

    <PageShell
        title="Leave calendar"
        :description="`${month.label} · ${peopleAway} ${peopleAway === 1 ? 'person' : 'people'} away at some point this month`"
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Leave', href: leaveRoutes.queue() }, { label: 'Calendar' }]"
    >
        <template #actions>
            <div class="flex flex-wrap items-center gap-2">
                <Button as-child variant="outline">
                    <Link :href="leaveRoutes.queue()">
                        <ListChecks class="size-4" aria-hidden="true" />
                        Queue
                    </Link>
                </Button>
                <Button as-child variant="outline">
                    <Link :href="leaveRoutes.balances">
                        <Scale class="size-4" aria-hidden="true" />
                        Balances
                    </Link>
                </Button>
            </div>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold tracking-tight">{{ month.label }}</h2>

                <div class="flex items-center gap-2">
                    <Button as-child variant="outline" size="icon">
                        <Link :href="leaveRoutes.calendar(month.previous)" preserve-scroll>
                            <ChevronLeft class="size-4" aria-hidden="true" />
                            <span class="sr-only">Previous month</span>
                        </Link>
                    </Button>
                    <Button as-child variant="outline" size="icon">
                        <Link :href="leaveRoutes.calendar(month.next)" preserve-scroll>
                            <ChevronRight class="size-4" aria-hidden="true" />
                            <span class="sr-only">Next month</span>
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- The legend. Two words and two tones, so nothing on the grid is a colour alone. -->
            <ul class="flex flex-wrap gap-2">
                <li><StatusBadge status="done" label="Approved" size="sm" /></li>
                <li><StatusBadge status="waiting" label="Pending" size="sm" /></li>
            </ul>

            <LeaveCalendar :days="days" />

            <!--
                The same month as a list of requests, under the grid. It is where the reason and
                the day count live — a cell has room for a name and a status and nothing else.
            -->
            <section aria-labelledby="month-requests" class="flex min-w-0 flex-col gap-3">
                <h2 id="month-requests" class="text-base font-semibold tracking-tight">Requests this month</h2>

                <ul v-if="requests.length" class="flex flex-col gap-3">
                    <li v-for="request in requests" :key="request.id">
                        <LeaveRequestCard :request="request" />
                    </li>
                </ul>

                <Card v-else class="p-4">
                    <EmptyState
                        :icon="Inbox"
                        title="Nobody is away this month"
                        description="Approved and pending leave for this month would be listed here."
                    />
                </Card>
            </section>
        </div>
    </PageShell>
</template>
