<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Bell, CalendarClock, CalendarOff, History, MessageSquareWarning, Video } from '@lucide/vue';
import type { AttendanceDay } from '@/Components/Attendance/attendance';
import ClockWidget from '@/Components/Attendance/ClockWidget.vue';
import TimerHeroCard from '@/Components/Dashboard/TimerHeroCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { Holiday } from '@/Components/Holidays/holidays';
import UpcomingHolidaysCard from '@/Components/Holidays/UpcomingHolidaysCard.vue';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import type { MyTaskBucket } from '@/Components/Tasks/MyTasks.vue';
import { bucketIcon, bucketSubline } from '@/Components/Tasks/MyTasks.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { TrackingMode } from '@/types';
import { computed } from 'vue';

defineOptions({ layout: EmployeeLayout });

/**
 * `taskStats` is the plan's five cards — My Tasks, Due Today, Overdue, In Progress,
 * Completed — each one a server-side COUNT over `Task::visibleTo()` narrowed to this person,
 * and each one carrying the link to the bucket it counted. Nothing on this page computes a
 * number, and there is no list here it could have been computed from.
 */
const props = defineProps<{
    greetingName: string;
    today: string;
    trackingMode: TrackingMode;
    taskStats: MyTaskBucket[];
    /**
     * The hero, whichever kind of day this person has. Exactly one of the two is non-null and
     * the server decided which, from `tracking_mode` — a person who tracks neither way gets
     * no hero rather than an empty card.
     */
    timer: { counted_seconds: number; pending_seconds: number; target_seconds: number | null } | null;
    attendance: { today: AttendanceDay; can_clock: boolean } | null;
    /**
     * The next few company holidays, today included.
     *
     * The same `HolidayService::upcoming()` the Company dashboard reads, through the same
     * resource — a holiday is a fact about the company, not about the person looking at it, so
     * there is nothing here to scope and no way for the two screens to disagree. Empty is an
     * answer ("nothing on the calendar"), not a placeholder.
     */
    upcomingHolidays: Holiday[];
    /**
     * This person's own leave, in three numbers (Part D §9's My Leave card).
     *
     * Null for somebody with no employee record, who has no leave to have. Everything in it is
     * about the reader — there is no total across the team and no comparison with anybody
     * (Part H §1).
     */
    leave: {
        balances: { name: string; days: number }[];
        pending: number;
        correction_requested: number;
        href: string;
    } | null;
}>();

/**
 * The capped type this person has most days of, for the card's one number.
 *
 * The card prints one balance and links to My Leave, where all four are. Picking the largest
 * rather than the first is what keeps the card useful for somebody who has spent their Annual
 * leave and still has Sick days: it answers "have I got any leave" rather than "have I got any
 * Annual leave". Ties keep the server's order, which is the order every leave picker uses.
 */
const largestBalance = computed(() =>
    (props.leave?.balances ?? []).reduce<{ name: string; days: number } | null>(
        (best, row) => (best === null || row.days > best.days ? row : best),
        null,
    ),
);

/**
 * The panels this page is still waiting on, each naming the phase that brings it.
 *
 * **A marker has to name a phase that has not happened.** Two of these said "Arrives in
 * Phase 2" while Phase 2 was shipping the very things they described, which is a placeholder
 * that has stopped being one. Notifications are live, so that panel now points at them
 * (below, outside this list). "Recent activity" is still unbuilt: the master prompt's Part I
 * table maps the employee "Recent activity" list to *Phase 2 / 7*, and Phase 2 closes without
 * it, so 7 is the number the plan itself leaves.
 */
const panels = [
    { title: 'Upcoming meetings', phase: 7, icon: Video, description: 'Meetings you are invited to will show here.' },
    // "Upcoming holidays" has left this list: Phase 5 built it, and a marker has to name a
    // phase that has not happened. It is `UpcomingHolidaysCard` below, reading the same
    // `HolidayService` the Company dashboard and the attendance grid read — so a holiday says
    // the same thing on every screen in the application.
    { title: 'Recent activity', phase: 7, icon: History, description: 'Your latest task changes will show here.' },
];
</script>

<template>
    <Head title="Dashboard" />

    <!--
        No #actions slot: the hero card carries this page's only primary action, so the
        header stays the greeting and nothing else.
    -->
    <PageShell title="Dashboard" :greeting="{ name: greetingName, today }">
        <TimerHeroCard
            v-if="timer"
            :counted-seconds="timer.counted_seconds"
            :pending-seconds="timer.pending_seconds"
            :target-seconds="timer.target_seconds"
        />
        <ClockWidget v-else-if="attendance" :today="attendance.today" :can-clock="attendance.can_clock" />

        <!--
            Cards rather than the read-only row this used to be: every one of them is now a
            real number that leads to the tasks it counted, and a number you can act on is a
            card, not a term in a definition list. Zero is a real answer here too — the
            sub-line under a nought reads "Nothing is late", not an empty state.
        -->
        <section
            v-if="taskStats.length > 0"
            aria-label="My tasks"
            class="grid min-w-0 grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5"
        >
            <StatCard
                v-for="stat in taskStats"
                :key="stat.key"
                size="compact"
                :label="stat.label"
                :value="stat.count"
                :sub="bucketSubline(stat.key, stat.count)"
                :icon="bucketIcon(stat.key)"
                :href="stat.href"
            />
        </section>

        <!--
            My Leave (Part D §9's "My Leave" card in the My Work group).

            Three numbers, all of them about this person: the largest balance they have, what is
            waiting on an approver, and — leading the sub-line when there is one — what has been
            sent back for them to answer. Nothing here is anybody else's leave and nothing is a
            comparison (Part H §1). Zero is an answer: "nothing waiting" is a sentence, not a
            blank.
        -->
        <section v-if="leave" aria-label="My leave" class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <StatCard
                size="compact"
                label="Leave balance"
                :value="largestBalance?.days ?? 0"
                :sub="largestBalance ? `${largestBalance.name} — most days left` : 'No balance set yet'"
                :icon="CalendarOff"
                :href="leave.href"
            />
            <StatCard
                size="compact"
                label="Leave waiting"
                :value="leave.pending"
                :sub="leave.pending === 0 ? 'Nothing waiting on an approver' : 'Waiting on a decision'"
                :icon="CalendarClock"
                :href="leave.href"
            />
            <StatCard
                v-if="leave.correction_requested > 0"
                size="compact"
                label="Needs your correction"
                :value="leave.correction_requested"
                sub="Sent back to you — amend and send it again"
                :icon="MessageSquareWarning"
                :href="leave.href"
            />
        </section>

        <section aria-label="Coming up" class="grid gap-4 md:grid-cols-2">
            <!--
                Notifications are built, so this panel is not a placeholder any more. It does
                not re-list the newest ten — the bell already does, from one polled endpoint,
                and a second reader of it here would be the duplicate DESIGN.md §5.8 forbids.
                It names where they are and opens the Center.
            -->
            <Card class="min-w-0 gap-4 p-6 shadow-xs">
                <h2 class="text-sm font-medium">Notifications</h2>
                <EmptyState
                    :icon="Bell"
                    title="In the bell, and in the Center"
                    description="Assignments, review verdicts and comments land on the bell in the top bar, with an unread count. The Center keeps all of them."
                >
                    <template #action>
                        <Button as-child size="sm" variant="outline">
                            <Link href="/notifications">Open the Notification Center</Link>
                        </Button>
                    </template>
                </EmptyState>
            </Card>

            <!--
                Real rows since Phase 5, in the slot the "Arrives in Phase 5" placeholder used
                to hold. No manage link: this shell has no holiday screen to send anybody to,
                and a control the endpoint would refuse is the lie DESIGN.md §5.11 forbids.
            -->
            <UpcomingHolidaysCard :holidays="upcomingHolidays" />

            <Card v-for="panel in panels" :key="panel.title" class="min-w-0 gap-4 p-6 shadow-xs">
                <h2 class="text-sm font-medium">{{ panel.title }}</h2>
                <EmptyState
                    :icon="panel.icon"
                    :title="`Arrives in Phase ${panel.phase}`"
                    :description="panel.description"
                />
            </Card>
        </section>
    </PageShell>
</template>
