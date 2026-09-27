<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarOff,
    CircleAlert,
    ClipboardCheck,
    CircleCheck,
    FolderKanban,
    HandCoins,
    Inbox,
    Plus,
    Receipt,
    Scale,
    TrendingUp,
    UserCheck,
    Users,
    UserX,
} from '@lucide/vue';
import { computed, type Component } from 'vue';
import { formatMinutes } from '@/Components/Attendance/attendance';
import AttentionList, { type AttentionItem } from '@/Components/Dashboard/AttentionList.vue';
import BarCompare, { type BarCompareItem } from '@/Components/Charts/BarCompare.vue';
import DonutBreakdown, { type DonutSlice } from '@/Components/Charts/DonutBreakdown.vue';
import { formatMoney } from '@/Components/Finance/finance';
import type { FinanceMonthSummary } from '@/Components/Finance/financeReport';
import type { DashboardPayrollCard } from '@/Components/Payroll/payslip';
import type { Holiday } from '@/Components/Holidays/holidays';
import UpcomingHolidaysCard from '@/Components/Holidays/UpcomingHolidaysCard.vue';
import type { Meeting } from '@/Components/Meetings/meetings';
import UpcomingMeetingsCard from '@/Components/Meetings/UpcomingMeetingsCard.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { DASHBOARD_POLL_MS } from '@/Components/Realtime/live';
import { useLiveProps } from '@/Components/Realtime/reload';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/** A tier-1 card: the label, the count, and the list it opens. All three from the server. */
interface WorkStat {
    key: string;
    label: string;
    count: number;
    href: string;
}

/**
 * One thing this Admin should open. The server decides what is on the list, what it says and
 * where it goes; the screen only chooses the icon, which cannot travel as JSON.
 */
interface AttentionRow {
    id: string;
    /** `review` or `overdue` — which of the panel's sources put it here. */
    kind: string;
    title: string;
    meta: string;
    href: string;
    tone: 'default' | 'urgent';
}

/**
 * One remote-timer employee's day — AC2's "Tapu 4h 18m / 5h".
 *
 * `tracked_minutes` and `target_minutes` are two durations printed side by side. Nothing
 * divides one by the other, and no row is compared with any other row: hours are a record, not
 * a rating (Part H §1). `target_minutes` is null for somebody with no schedule, and the line
 * then shows a duration and no target rather than counting against a number nobody set.
 */
interface RemoteTimeRow {
    id: number;
    name: string;
    tracked_minutes: number;
    target_minutes: number | null;
    pending_minutes: number;
}

/**
 * Today's attendance, as the server counted it. Absent from the payload entirely for somebody
 * who may not manage other people's attendance, which is why every field is optional here.
 */
interface AttendanceToday {
    present?: number;
    absent?: number;
    /**
     * How many distinct PEOPLE have approved leave covering today (Phase 5). Counted off
     * `leave_requests`, not off Leave attendance rows — a remote-timer employee has no
     * attendance rows at all (decision 4-11), so counting rows would have left them out of the
     * one number that is about people being away.
     */
    on_leave?: number;
    leave_href?: string;
    href?: string;
    remote?: RemoteTimeRow[];
}

/** One slice of "Tasks by status", already counted and already toned by the server. */
interface TaskStatusCount {
    key: string;
    label: string;
    tone: string;
    count: number;
}

/**
 * One bar of "Tasks by employee": a person and how many open tasks they hold.
 *
 * A **count**, never a score (Part H §1, Part B §3 rule 12). The rows arrive ordered by name and
 * nothing here re-orders them — sorting by `count` would turn the chart into a league table, and
 * the first bar of one reads as the winner. There is no ratio, no target and no comparison with
 * anybody or with last week.
 */
interface EmployeeTaskCount {
    id: number;
    name: string;
    count: number;
    /** `/admin/tasks` under the exact two filters the count was made with. */
    href: string;
}

/** One bar of "Projects by type": the type, its count, and the filtered list it opens. */
interface ProjectTypeCount {
    key: string;
    label: string;
    count: number;
    href: string;
}

/**
 * One row of "Upcoming deadlines" — a project, when it is due in words, and its own page.
 *
 * Part D §3 lists this beside three charts and budgets three charts, so it is the LIST. The
 * words in `meta` are the second encoding of `tone`, never the other way round.
 */
interface DeadlineRow {
    id: string;
    title: string;
    meta: string;
    href: string;
    tone: 'default' | 'urgent';
}

const props = defineProps<{
    greetingName: string;
    today: string;
    stats: {
        activeEmployees: number;
    };
    /**
     * Present today, Absent, and the remote-timer employees' tracked time — counted on the
     * server from the same roster the Attendance screen draws, so the card and the screen it
     * opens cannot disagree. `{}` for a viewer who may not manage other people's attendance.
     *
     * On leave joined them in Phase 5 and is a real count now. **Zero is an answer** here —
     * "nobody is on approved leave today" is a measurement somebody took, which is exactly what
     * the placeholder that used to sit in this slot could not say.
     */
    attendance: AttendanceToday;
    /**
     * The plan's five task cards for this dashboard — Tasks due today, Overdue, Awaiting
     * review, Completed today, Active projects. Every one is a server-side COUNT scoped by
     * `Task::visibleTo()` / `Project::visibleTo()` over the whole agency (this is the Company
     * dashboard, not the Admin's own plate — that is `/admin/my-tasks`), and every one links
     * to the same query as a list.
     */
    workStats: WorkStat[];
    /**
     * The tier-2 panel's feed: tasks awaiting this Admin's review, then what is overdue. Every
     * row is a query scoped by `Task::visibleTo()` with its predicate from `TaskBucket`, and
     * every row carries the URL of the task itself. Empty when they hold no `tasks.view`.
     */
    attention: AttentionRow[];
    /** Open tasks per status, for the donut. Server-counted, server-toned. */
    taskStatuses: TaskStatusCount[];
    /**
     * Row 2's second chart — open tasks per person, ordered by name.
     *
     * Every figure is `WorkloadService`'s, which is one `TaskService::count()` per person with
     * `assignee_id` and `TaskBucket::Open`: the same query `/admin/workload` prints and the same
     * query the bar's `href` opens. Nothing on this page adds these up — a task with two
     * assignees is on both of their bars, so a sum of them is higher than the agency's total and
     * would be wrong however it was labelled.
     */
    tasksByEmployee: EmployeeTaskCount[];
    /**
     * Row 2's third chart — projects per type, every type including the empty ones.
     *
     * Counted over `Project::visibleTo()` not archived, which is what `/admin/projects` starts
     * from, so each bar's link opens exactly the projects it counted. `[]` for a viewer who may
     * not see projects at all — absent, not eight zeroes (Part C §1).
     */
    projectsByType: ProjectTypeCount[];
    /**
     * Row 2's fourth item, and the one that is not a chart: the next few **project** deadlines.
     *
     * Task dates are already answered three times on this screen (two cards and the attention
     * panel), so this is `projects.deadline` — the date a client was promised and the one date
     * nothing else here shows.
     */
    upcomingDeadlines: DeadlineRow[];
    /**
     * The next few company holidays, today included — Part D §3's card, recorded from the
     * design references (Part I).
     *
     * Straight from `HolidayService`, which is the same service the attendance derivation and
     * the Holidays screen read, so this card and the month grid cannot disagree about whether
     * Thursday is Victory Day. Empty is an answer, not a placeholder: it means the calendar is
     * clear, and the card then offers the screen where next year's gazette is typed in.
     */
    upcomingHolidays: Holiday[];
    /**
     * The next few meetings, soonest first — Part D §12's card, Phase 7.
     *
     * One query scoped by `Meeting::visibleTo()` and capped at five on the server, never the
     * whole table filtered here. An Admin's scope is the agency's whole diary, which is what
     * makes this the Company dashboard's version of the card an employee also has: same
     * component, same `MeetingResource` rows, different scope — and the scope is the one thing
     * neither screen decides for itself.
     */
    upcomingMeetings: Meeting[];
    /**
     * Row 3 — this month's income, expense, payroll and operating result (Part D §3, Phase 8).
     *
     * The two real figures are `FinanceService::monthlyRollup()`'s, the same call `/finance`
     * makes, so this row and the Finance dashboard cannot disagree about September;
     * `operating_result` is that rollup's own net, computed in integer cents. **`payroll` is
     * null with the phase that brings it** — Phase 9 owns `payroll_periods` and it does not
     * exist, so the card is a marked placeholder rather than a zero that would read as "we paid
     * nobody this month".
     *
     * `{}` for a viewer without `finance.view` — absent from the payload rather than zeroed
     * (Part C §1), which is why every key is optional.
     */
    finance: FinanceMonthSummary & DashboardPayrollCard;
}>();

/**
 * The icon each of the five wears. Icons are components, so they cannot come from PHP; the
 * label, the number and the destination all do.
 */
const WORK_ICON: Record<string, Component> = {
    due_today: CalendarClock,
    overdue: CircleAlert,
    in_review: ClipboardCheck,
    completed_today: CircleCheck,
    active_projects: FolderKanban,
};

/**
 * What each card says under its number, at zero and above it.
 *
 * Zero is an answer and reads like one: "Nothing is late" is the best line on this page. It
 * is also the second encoding — an overdue count that were only emphasised would say nothing
 * in greyscale and nothing to a screen reader (DESIGN.md §6 rule 6).
 */
const WORK_SUB: Record<string, { zero: string; some: string }> = {
    due_today: { zero: 'Nothing due today', some: 'Due before the day is out' },
    overdue: { zero: 'Nothing is late', some: 'Past their due date' },
    in_review: { zero: 'Nothing waiting on a reviewer', some: 'Waiting on a reviewer' },
    completed_today: { zero: 'None finished yet today', some: 'Finished today' },
    active_projects: { zero: 'No active projects', some: 'Being worked on' },
};

function subFor(stat: WorkStat): string {
    const copy = WORK_SUB[stat.key];

    return copy ? (stat.count === 0 ? copy.zero : copy.some) : '';
}

/**
 * The icon each kind of attention row wears. Same split as WORK_ICON above and for the same
 * reason: an icon is a component, so it cannot come from PHP. What the row SAYS, where it goes
 * and whether it is urgent all do.
 */
const ATTENTION_ICON: Record<string, Component> = {
    review: ClipboardCheck,
    overdue: CircleAlert,
};

const attentionItems = computed<AttentionItem[]>(() =>
    props.attention.map((row) => ({
        id: row.id,
        icon: ATTENTION_ICON[row.kind] ?? Inbox,
        title: row.title,
        meta: row.meta,
        href: row.href,
        tone: row.tone,
    })),
);

/**
 * The donut's slices. The tone is the server's `TaskStatus::tone()`, which is the same mapping
 * every `StatusBadge` on every other screen uses — so the ring and the badges agree, and there
 * is no second copy of it here to drift (decision 2-28's reasoning, applied to a colour).
 */
const taskStatusSlices = computed<DonutSlice[]>(() =>
    props.taskStatuses.map((status) => ({
        label: status.label,
        value: status.count,
        tone: status.tone as StatusKey,
    })),
);

/**
 * The two bar charts' data, and nothing else done to it.
 *
 * `map`, not `sort`, `filter` or `reduce`: the server decided the order (by name for people, by
 * the enum for types) and the counts are the server's. A `sort((a, b) => b.count - a.count)` here
 * is the ranking Part H §1 forbids, and it would be one line.
 */
const employeeBars = computed<BarCompareItem[]>(() =>
    props.tasksByEmployee.map((row) => ({ label: row.name, value: row.count })),
);

const projectTypeBars = computed<BarCompareItem[]>(() =>
    props.projectsByType.map((row) => ({ label: row.label, value: row.count })),
);

/**
 * "Upcoming deadlines" as `AttentionList` rows — the same panel component the attention feed
 * uses, because a deadline is a thing you open and DESIGN.md §5.8 forbids a second way to draw a
 * card of linked rows. The icon is chosen here because an icon is a component and cannot travel
 * as JSON; the words, the destination and the tone all come from the server.
 */
const deadlineItems = computed<AttentionItem[]>(() =>
    props.upcomingDeadlines.map((row) => ({
        id: row.id,
        icon: CalendarClock,
        title: row.title,
        meta: row.meta,
        href: row.href,
        tone: row.tone,
    })),
);

/** True once the server sent the attendance block at all — see the prop's docblock. */
const hasAttendance = computed(() => props.attendance.present !== undefined);

/**
 * `4h 18m / 5h`, or `4h 18m` when the person has no schedule to compare against.
 *
 * Two durations, printed. Never a percentage and never a ratio presented as a verdict: the
 * target is what their schedule says the day is, not a bar somebody is measured against.
 */
function remoteTime(row: RemoteTimeRow): string {
    const tracked = formatMinutes(row.tracked_minutes);

    return row.target_minutes === null ? tracked : `${tracked} / ${formatMinutes(row.target_minutes)}`;
}

/**
 * Tier 4 — Row 3. Money is a footnote on the company dashboard, so these stay the compact
 * card: same anatomy, smaller number, clearly below the hero row.
 *
 * All four carry real figures now. Three come from `FinanceService::monthlyRollup()` (Phase 8);
 * **Payroll is Phase 9's** — this month's `payroll_periods` row, its status and the sum of its
 * items' `net_salary`, all resolved on the server. It falls back to `StatCard`'s em-dash and
 * "Not available yet" only when the month has **no period yet**, which is every 1st before
 * `hq:create-payroll-draft` runs; `payroll_note` then says so in words, because a zero would
 * claim the agency paid nobody this month.
 *
 * The **operating result** says what it is and what it is not, because a figure by that name
 * which quietly omitted the largest cost would be worse than no figure at all. Expenses filed
 * under the *Payroll* category are in it — they are expenses somebody entered. What is not in
 * it is the payroll RUN: `payroll_items` is a different table and the rollup never reads it.
 * That was true before Phase 9 and is still true; only the wording lost its phase number.
 */
const hasFinance = computed(() => props.finance.income !== undefined);

const financeCards = computed(() => [
    {
        key: 'income',
        label: 'Income',
        value: formatMoney(props.finance.income ?? '0', props.finance.currency),
        sub: 'Recorded this month',
        icon: TrendingUp,
        href: props.finance.href,
    },
    {
        key: 'expense',
        label: 'Expenses',
        value: formatMoney(props.finance.expense ?? '0', props.finance.currency),
        sub: 'Recorded this month',
        icon: Receipt,
        href: props.finance.href,
    },
    {
        key: 'payroll',
        label: 'Payroll',
        // Real from Phase 9: this month's period total. `undefined` — an em-dash and "Not
        // available yet" — only when there is NO period for the month yet, which is every 1st
        // before the draft command runs. A zero would claim we paid nobody; the sub-line says
        // which of the two it is, in words, from the server.
        value:
            props.finance.payroll === null || props.finance.payroll === undefined
                ? undefined
                : formatMoney(props.finance.payroll, props.finance.currency),
        sub: props.finance.payroll_note,
        icon: HandCoins,
        href: props.finance.payroll_href ?? undefined,
    },
    {
        key: 'operating_result',
        label: 'Operating result',
        value: formatMoney(props.finance.operating_result ?? '0', props.finance.currency),
        // Still true after Phase 9, and worth keeping: `payroll_items` is a different table
        // and `FinanceService::monthlyRollup()` never reads it, so the wage bill on the card
        // to the left is not inside this figure. Only the tense changed — a sentence still
        // naming a phase number would read as unbuilt work rather than as an accounting fact.
        sub: 'Income less expenses. The payroll run is not in it.',
        icon: Scale,
        href: props.finance.href,
    },
]);

/** What the row shows to somebody who may not see the books: four placeholders, as before. */
const financePlaceholders = [
    { key: 'income', label: 'Income', phase: 8, icon: TrendingUp },
    { key: 'expense', label: 'Expenses', phase: 8, icon: Receipt },
    // No phase number since Phase 9 shipped: this row is what somebody WITHOUT `finance.view`
    // sees, and "Arrives in Phase 9" would now be false. It prints an em-dash and "Not
    // available yet", which is what the blank actually means to that reader.
    { key: 'payroll', label: 'Payroll', phase: undefined, icon: HandCoins },
    { key: 'operating_result', label: 'Operating result', phase: 8, icon: Scale },
];

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
 */
useLiveProps(
    ['workStats', 'attention', 'taskStatuses', 'tasksByEmployee', 'attendance', 'upcomingDeadlines'],
    { intervalMs: DASHBOARD_POLL_MS },
);
</script>

<template>
    <Head title="Company dashboard" />

    <PageShell title="Company dashboard" :greeting="{ name: greetingName, today }">
        <template #actions>
            <Button as-child>
                <Link href="/admin/projects/create">
                    <Plus aria-hidden="true" />
                    New project
                </Link>
            </Button>
        </template>

        <!--
            Tier 1 — the five the plan names, and what an Admin opens this page for: what is
            late and what is waiting on them. Each one leads to the list it counted.
        -->
        <section
            v-if="workStats.length > 0"
            aria-label="Work today"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
        >
            <StatCard
                v-for="stat in workStats"
                :key="stat.key"
                :label="stat.label"
                :value="stat.count"
                :sub="subFor(stat)"
                :icon="WORK_ICON[stat.key]"
                :href="stat.href"
            />
        </section>

        <!--
            Tier 1b — the people numbers, at a lower weight than the work.

            Present today and Absent carry real counts now and both lead to the roster, which is
            where the split between Present and Late lives. On leave is a real number since
            Phase 5 and leads to the leave calendar — and zero on it is an answer, not a blank:
            "nobody is on approved leave today" is a claim `leave_requests` can now support.
        -->
        <section aria-label="Team today" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                size="compact"
                label="Active employees"
                :value="stats.activeEmployees"
                sub="Current headcount · no history to compare yet"
                :icon="Users"
            />
            <StatCard
                v-if="hasAttendance"
                size="compact"
                label="Present today"
                :value="attendance.present"
                :sub="attendance.present === 0 ? 'Nobody has clocked in yet' : 'In today, including late arrivals'"
                :icon="UserCheck"
                :href="attendance.href"
            />
            <StatCard
                v-if="hasAttendance"
                size="compact"
                label="Absent"
                :value="attendance.absent"
                :sub="attendance.absent === 0 ? 'Nobody is marked absent' : 'Scheduled to work, no record'"
                :icon="UserX"
                :href="attendance.href"
            />
            <StatCard
                v-if="hasAttendance"
                size="compact"
                label="On leave"
                :value="attendance.on_leave"
                :sub="attendance.on_leave === 0 ? 'Nobody is on approved leave today' : 'Approved leave covering today'"
                :icon="CalendarOff"
                :href="attendance.leave_href"
            />
        </section>

        <!--
            AC2 — "Tapu 4h 18m / 5h". One line per remote-timer employee, read from the
            `daily_work_summary` view, which is the reporting join Part C rule 6 asks for.

            Two durations side by side and nothing divided by anything: no percentage, no bar, no
            ordering of people by hours (Part H §1). Anything still waiting for a sign-off is
            named in words and is not in the figure beside it — decision 4-7 — and the line links
            to the screen where it can be signed off.
        -->
        <section
            v-if="hasAttendance && attendance.remote?.length"
            aria-labelledby="remote-time-today"
            class="flex flex-col gap-4"
        >
            <h2 id="remote-time-today" class="text-base font-semibold tracking-tight">Remote time today</h2>
            <Card class="min-w-0 p-6">
                <ul class="flex min-w-0 flex-col divide-y">
                    <li
                        v-for="row in attendance.remote"
                        :key="row.id"
                        class="flex min-w-0 flex-col gap-1 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4"
                    >
                        <Link
                            :href="`/attendance/${row.id}`"
                            class="truncate text-sm font-medium underline-offset-4 hover:underline"
                        >
                            {{ row.name }}
                        </Link>
                        <div class="flex shrink-0 flex-col items-start gap-0.5 sm:items-end">
                            <span class="text-sm tabular-nums">{{ remoteTime(row) }}</span>
                            <Link
                                v-if="row.pending_minutes > 0"
                                href="/admin/time"
                                class="text-xs tabular-nums text-status-waiting-fg underline-offset-4 hover:underline"
                            >
                                {{ formatMinutes(row.pending_minutes) }} waiting for approval — not counted
                            </Link>
                        </div>
                    </li>
                </ul>
            </Card>
        </section>

        <!--
            Tier 2 — the block that tells you what to do, before any chart.

            It has a feed now (decision 2-50): what is waiting on this Admin's verdict, then
            what is late. Both are server queries scoped by `Task::visibleTo()`, both link to
            the task, and the empty state is now a real answer rather than a placeholder — the
            panel used to sit under a card counting six overdue tasks saying nothing needed
            anybody.

            The `note` stays whether or not there are rows. Two of this panel's four sources are
            not built, and a list that silently dropped that sentence the moment it had one item
            in it would claim to be the whole of what needs you.
        -->
        <!--
            Tier 2 shares its row with "Upcoming holidays" (Part D §3, recorded from the design
            references in Part I). Two thirds and one third rather than two full-width blocks:
            the attention panel is the thing you act on and the holidays are the thing you plan
            around, so one leads and the other sits beside it. Below `lg` they stack, attention
            first, because on a phone the order IS the priority.
        -->
        <section aria-label="Attention and holidays" class="grid min-w-0 gap-4 lg:grid-cols-3">
            <div class="min-w-0 lg:col-span-2">
                <AttentionList
                    title="Needs your attention"
                    :items="attentionItems"
                    :empty-icon="Inbox"
                    empty-title="Nothing is waiting on you"
                    empty-description="No task is sitting in your review queue, and nothing is past its due date."
                    note="Tasks only for now — approvals arrive in Phases 8–9 and leave decisions in Phase 5."
                />
            </div>

            <!--
                The company calendar, four rows deep. It links to the screen the client types
                their own holidays into, because most of the seeded Bangladeshi dates are lunar
                estimates and the Admin reading this card is the person who corrects them.
            -->
            <UpcomingHolidaysCard :holidays="upcomingHolidays" manage-href="/admin/holidays" />
        </section>

        <!--
            The diary. Its own row rather than a third column beside the two above: at `lg` that
            row is already two thirds and one third, and a meeting's title needs the width more
            than a holiday's name does. It links to Meetings, where one is scheduled.
        -->
        <section aria-label="Upcoming meetings" class="grid min-w-0 gap-4">
            <UpcomingMeetingsCard :meetings="upcomingMeetings" />
        </section>

        <!--
            Tier 3 — Part D §3's Row 2, and the screen's **whole chart budget**: Tasks by status,
            Tasks by employee, Projects by type. Three, and the fourth thing Row 2 names —
            Upcoming deadlines — is the list below them, not a chart.

            The "Attendance, last 14 days" card that used to sit beside the donut is gone. It was
            `:data="[]"` on a database with a month of seeded attendance in it, it is in neither
            Part D §3's Row 2 nor anywhere else in the plan, and Part I says in as many words that
            the reference's attendance chart is what to LEAVE: attendance shows as the
            Present/Absent/On-leave cards above. Keeping it would also have made this screen's
            fourth chart.
        -->
        <section aria-label="Work by status and by person" class="grid gap-4 lg:grid-cols-2">
            <Card class="min-w-0 gap-4 p-6">
                <h2 class="text-sm font-medium">Tasks by status</h2>
                <div class="flex min-h-56 min-w-0 flex-col justify-center">
                    <!--
                        The open work, split by status — the same scoped, non-archived set the
                        cards above are counted from, so the centre number and "Overdue" can be
                        reconciled against each other. It was `:data="[]"` under copy reading
                        "fills in once there is something to count" on a dashboard with 25
                        tasks on it.
                    -->
                    <DonutBreakdown :data="taskStatusSlices" center-label="Open tasks" />
                </div>
            </Card>
            <Card class="min-w-0 gap-4 p-6">
                <div class="flex min-w-0 items-center justify-between gap-4">
                    <h2 class="text-sm font-medium">Tasks by employee</h2>
                    <!--
                        The chart cannot carry a link per bar, so the header carries the one to
                        the screen where every one of these counts IS a link under the filter it
                        was counted with. The numbers here are that screen's own.
                    -->
                    <Link
                        v-if="tasksByEmployee.length > 0"
                        href="/admin/workload"
                        class="shrink-0 text-xs text-muted-foreground underline-offset-4 hover:underline"
                    >
                        Open Workload
                    </Link>
                </div>
                <div class="flex min-h-56 min-w-0 flex-col justify-center">
                    <!--
                        Open tasks per person, **by name**. Horizontal because the categories are
                        people's names and a vertical axis cannot hold one. No ordering by count,
                        no target line, no percentage — a bar is a count of tasks (Part H §1).
                    -->
                    <BarCompare :data="employeeBars" orientation="horizontal" label="Open tasks" :height="240" />
                </div>
            </Card>
        </section>

        <section aria-label="Projects by type and deadlines" class="grid min-w-0 gap-4 lg:grid-cols-2">
            <Card class="min-w-0 gap-4 p-6">
                <div class="flex min-w-0 items-center justify-between gap-4">
                    <h2 class="text-sm font-medium">Projects by type</h2>
                    <Link
                        v-if="projectsByType.length > 0"
                        href="/admin/projects"
                        class="shrink-0 text-xs text-muted-foreground underline-offset-4 hover:underline"
                    >
                        See all
                    </Link>
                </div>
                <div class="flex min-h-56 min-w-0 flex-col justify-center">
                    <BarCompare :data="projectTypeBars" orientation="horizontal" label="Projects" :height="240" />
                </div>
            </Card>

            <!--
                Row 2's fourth item, as a list. Every row is the project's own page, and how near
                the date is is printed in words — the medallion is the second encoding of the
                sentence, never the only one (DESIGN.md §5.6).
            -->
            <!--
                The wrapping `min-w-0` is not decoration. `AttentionList`'s own root `Card` does
                not carry one, so in a grid track it grows to fit its longest project name and
                drags the whole column — chart included — past the viewport. Measured at 375, where
                one deadline row made this row 410 px wide inside a 343 px page. The attention
                panel above wraps itself the same way for the same reason.
            -->
            <div class="min-w-0">
                <AttentionList
                    title="Upcoming deadlines"
                    :items="deadlineItems"
                    :empty-icon="CalendarClock"
                    empty-title="No deadline in the next month"
                    empty-description="No project that is still being worked on is due inside the next 30 days."
                >
                    <template #action>
                        <Link
                            v-if="upcomingDeadlines.length > 0"
                            href="/admin/projects"
                            class="shrink-0 text-xs text-muted-foreground underline-offset-4 hover:underline"
                        >
                            See all
                        </Link>
                    </template>
                </AttentionList>
            </div>
        </section>

        <!--
            Tier 4 — Row 3: this month's money, at the bottom and at a lower weight.

            Income, expenses and the operating result are real from Phase 8 and each card opens
            the Finance dashboard on the month it counted. Payroll is real from Phase 9 and
            opens the payroll workbench — see `financeCards` for why a month with no period yet
            is an em-dash and not a zero.
        -->
        <section aria-labelledby="this-month" class="flex flex-col gap-4">
            <h2 id="this-month" class="text-base font-semibold tracking-tight">
                This month<template v-if="finance.label"> — {{ finance.label }}</template>
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <template v-if="hasFinance">
                    <StatCard
                        v-for="card in financeCards"
                        :key="card.key"
                        size="compact"
                        :label="card.label"
                        :value="card.value"
                        :sub="card.sub"
                        :icon="card.icon"
                        :href="card.href"
                    />
                </template>
                <template v-else>
                    <StatCard
                        v-for="card in financePlaceholders"
                        :key="card.key"
                        size="compact"
                        :label="card.label"
                        :phase="card.phase"
                        :icon="card.icon"
                    />
                </template>
            </div>
        </section>
    </PageShell>
</template>
