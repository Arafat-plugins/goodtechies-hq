<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Bell, CalendarClock, CalendarDays, CalendarOff, History, MessageSquareWarning } from '@lucide/vue';
import type { AttendanceDay } from '@/Components/Attendance/attendance';
import ClockWidget from '@/Components/Attendance/ClockWidget.vue';
import AttentionList, { type AttentionItem } from '@/Components/Dashboard/AttentionList.vue';
import TimerHeroCard from '@/Components/Dashboard/TimerHeroCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { Holiday } from '@/Components/Holidays/holidays';
import UpcomingHolidaysCard from '@/Components/Holidays/UpcomingHolidaysCard.vue';
import type { Meeting } from '@/Components/Meetings/meetings';
import UpcomingMeetingsCard from '@/Components/Meetings/UpcomingMeetingsCard.vue';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import type { MyTaskBucket } from '@/Components/Tasks/MyTasks.vue';
import { bucketIcon, bucketSubline } from '@/Components/Tasks/MyTasks.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { DASHBOARD_POLL_MS } from '@/Components/Realtime/live';
import { useLiveProps } from '@/Components/Realtime/reload';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { TrackingMode } from '@/types';
import { computed } from 'vue';
import { clock12 } from '@/lib/clock';

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
     * The next few meetings this person is in, soonest first — one query scoped by
     * `Meeting::visibleTo()` and capped at five on the server, never the whole table filtered
     * here. `MeetingResource` rows, so the Join control on this card is the same component,
     * with the same rules, as the one on the Meetings list and on a meeting's own page.
     *
     * Empty is an answer ("nothing in the diary"), not a placeholder.
     */
    upcomingMeetings: Meeting[];
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
    /**
     * "My Schedule" (Part D §3) — the reader's own week, from `ScheduleService::rowFor()`.
     *
     * The same four facts the Admin's schedule editor writes and `/attendance` prints, so the
     * week cannot be described one way here and another way there. The weekday labels and the
     * word for office-or-remote both arrive resolved: nothing here maps a `sun` or picks between
     * "Office" and "Remote", because a second copy of either mapping is a second thing to drift.
     *
     * `null` for somebody with no employee record, and for an employee nobody has given a
     * schedule — the card says which in words rather than drawing a zero-hour week.
     */
    schedule: {
        days: string[];
        hours_per_day: number;
        start_time: string | null;
        location: string;
        href: string;
    } | null;
    /**
     * "Recent activity" (Part D §3, Part I's spec §23 row) — **the reader's own** task changes.
     *
     * `actor_id = the requester` on the server, so there is no shape of this payload that could
     * carry a colleague's movements: an activity feed of other people is the surveillance Part H
     * §1 forbids and spec §22 keeps off the Company dashboard. Each row's task is re-checked
     * against `Task::visibleTo()` before it is sent, because a task can be handed off after
     * somebody worked on it.
     *
     * Empty is an answer — nothing has been touched yet — not a placeholder.
     */
    recentActivity: { id: number; title: string; meta: string; href: string }[];
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
 * The week, in one line: `Sun, Mon, Tue, Wed, Thu · 8 h/day · starts 09:00`.
 *
 * The same sentence `Pages/Admin/Schedules/Index.vue` prints under a person's name, so the week
 * an Admin set and the week the employee reads are worded identically. Every part of it arrives
 * resolved from the server — this joins, it does not decide.
 */
const scheduleSummary = computed(() => {
    if (!props.schedule) {
        return null;
    }

    const days = props.schedule.days.join(', ') || 'No working days set';
    const start = props.schedule.start_time ? `starts ${clock12(props.schedule.start_time)}` : 'no start time';

    return `${days} · ${props.schedule.hours_per_day} h/day · ${start}`;
});

/**
 * "Recent activity" as `AttentionList` rows — the shared card of linked rows, not a second one
 * (DESIGN.md §5.8). The icon is chosen here because an icon is a component and cannot travel as
 * JSON; the sentence, the meta line and the destination are all the server's.
 */
const activityItems = computed<AttentionItem[]>(() =>
    props.recentActivity.map((row) => ({
        id: row.id,
        icon: History,
        title: row.title,
        meta: row.meta,
        href: row.href,
    })),
);

/* ---------------------------------------------------------------- keeping it current */

/**
 * The counters and the *Needs your attention* feed re-read themselves — POLISH-BACKLOG §A.3's
 * dashboard line, *"counters and 'needs your attention' refresh on a timer at minimum"*.
 *
 * **A timer, and only a timer, on both builds.** A dashboard has no channel and should not get
 * one: its props are a dozen aggregate queries over everything this reader may see, so a
 * "something changed" frame for it would have to be rung by every write in the application and
 * would say nothing useful when it arrived. §A.4's third rule is answered by saying that out
 * loud rather than by inventing a `dashboard.{user}` room.
 *
 * **Sixty seconds**, because nobody reads a dashboard as a clock and this is the heaviest
 * controller in the application — a partial reload runs it in full whether it answers thirteen
 * props or four (see `reload.ts`). Anything faster spends the client's machine on a number that
 * is the same number. The one figure on this screen that IS read as a clock, the running timer,
 * does not wait for this: it has its own heartbeat in `Components/Timer/timer.ts`.
 *
 * Named props rather than a bare reload: the dates, the holidays and the meetings on this screen
 * are not what changes minute to minute, and a full reload would replace every prop on the page
 * including ones a card holds local state against.
 *
 * `attendance` is in the list for §A.3's other line: a clock-in from the phone at the door lands
 * on the dashboard the laptop is showing, within a minute, without a reload.
 */
useLiveProps(['taskStats', 'timer', 'attendance', 'leave', 'recentActivity'], {
    intervalMs: DASHBOARD_POLL_MS,
});
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
            <Card class="min-w-0 gap-4 p-6">
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
            <!--
                Real rows since Phase 7, in the slot the "Arrives in Phase 7" placeholder used
                to hold. No create control: the card links to Meetings, which is where one is
                scheduled, and a second entry point would be a second place for the form to be
                reached differently.
            -->
            <UpcomingMeetingsCard :meetings="upcomingMeetings" />

            <UpcomingHolidaysCard :holidays="upcomingHolidays" />

            <!--
                "My Schedule" (Part D §3). It is the card that explains the hero above it: whether
                today is an Off Day and whether 9:07 was late are both answers about this week, and
                a clock widget reading "Off day" over a page that never says which days are working
                days is a state nobody can check.

                It links to the reader's own month, where the schedule is shown beside what it
                produced. No edit control: a schedule is the Admin's to set, and a control the
                server would refuse is the lie DESIGN.md §5.11 forbids.
            -->
            <Card class="min-w-0 gap-4 p-6">
                <div class="flex min-w-0 items-center justify-between gap-4">
                    <h2 class="text-sm font-medium">My schedule</h2>
                    <Link
                        v-if="schedule"
                        :href="schedule.href"
                        class="shrink-0 text-xs text-muted-foreground underline-offset-4 hover:underline"
                    >
                        My attendance
                    </Link>
                </div>
                <EmptyState
                    v-if="!schedule"
                    :icon="CalendarDays"
                    title="No schedule set yet"
                    description="Nobody has set your working days or hours. An Admin sets them, and until they do no day of yours counts as an off day."
                />
                <div v-else class="flex min-w-0 flex-col gap-1">
                    <p class="text-sm tabular-nums">{{ scheduleSummary }}</p>
                    <p class="text-xs text-muted-foreground">{{ schedule.location }}</p>
                </div>
            </Card>

            <!--
                "Recent activity" (Part D §3, and spec §23 for this surface only). The reader's own
                task changes, newest first — never anybody else's, which is decided on the server by
                `actor_id` and not by anything here.
            -->
            <!--
                The wrapping `min-w-0` is not decoration: `AttentionList`'s root `Card` carries
                none, so in a grid track it grows to fit its longest task title and drags the
                column past the viewport. Measured at 375 on the Company dashboard.
            -->
            <div class="min-w-0">
                <AttentionList
                    title="Recent activity"
                    :items="activityItems"
                    :empty-icon="History"
                    empty-title="Nothing yet"
                    empty-description="Your own task changes show up here — a status moved, a checklist ticked, a link added."
                />
            </div>
        </section>
    </PageShell>
</template>
