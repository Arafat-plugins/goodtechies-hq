<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarCheck,
    CalendarDays,
    CalendarRange,
    CircleAlert,
    CircleCheck,
    Clock,
    Hourglass,
    Timer,
} from '@lucide/vue';
import { formatMinutes } from '@/Components/Attendance/attendance';
import PageShell from '@/Components/PageShell.vue';
import type { EmployeeReportsPayload } from '@/Components/Reports/reports';
import { reportRoutes } from '@/Components/Reports/reports';
import StatCard from '@/Components/StatCard.vue';
import { bucketIcon, bucketSubline } from '@/Components/Tasks/MyTasks.vue';
import { cn } from '@/lib/utils';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';

/**
 * Employee → My Reports (Part D §15, report contract §5).
 *
 * **Not the Admin screen with a filter on it.** Part D §15 names a different list for an
 * employee — My Tasks, Completed, Pending, Overdue, Time, and a weekly or monthly work
 * summary — and it answers a different question: not "how is the agency doing" but "what have
 * I been doing". So there is no catalogue on it, no employee chip and no project chip; the one
 * choice is the length of the window, and it is in the URL.
 *
 * ## Absent, not zero
 *
 * `time` arrives only for somebody the timer tracks and `clocked` only for somebody the office
 * clock tracks. A key that is not there renders **nothing** — no empty card, no `0h 0m`, and
 * no `v-else`. A zero there would be a fact about somebody's tracking mode dressed up as a
 * fact about their work (Part C §1). This page never reads a role or a tracking mode to work
 * that out: the presence of the key is the whole answer, and the server is where the two
 * policies that decide it already live.
 *
 * ## Which numbers are links, and which are not
 *
 * The four plate counts are, and the `href` is the **server's** — the same `TaskBucket` the
 * count came from, so the number and the list it opens cannot be two different predicates.
 *
 * The three window figures under *This week* are not, and deliberately: the payload carries no
 * link for them, and a URL built here out of a date range and a bucket would be a second
 * statement of the same query — which is the exact drift `myBucket()` exists to prevent. They
 * are reported next to the counts that do open, so nothing is a dead end.
 */
defineOptions({ layout: EmployeeLayout });

defineProps<EmployeeReportsPayload>();

const PERIOD_LABEL: Record<string, string> = { week: 'This week', month: 'This month' };

/** The window is the URL, so a bookmark and the back button both work. */
const periodHref = (key: string): string => `${reportRoutes.employee}?period=${key}`;

const periodIcon = (key: string) => (key === 'month' ? CalendarRange : CalendarDays);

/**
 * The words under a count, borrowed from `MyTasks.vue` so a bucket says the same thing here as
 * on the page it opens. The server's whole-plate bucket (`all`) is not one of that page's
 * seven, so it is the one line supplied here rather than left blank beside three cards that
 * have one.
 */
function subFor(key: string, count: number): string {
    return bucketSubline(key, count) || 'Assigned to you';
}
</script>

<template>
    <Head title="My Reports" />

    <PageShell title="My Reports" :description="`Your own work, ${range.label}.`">
        <template #actions>
            <nav
                aria-label="Summary period"
                class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-md bg-muted p-1"
            >
                <Link
                    v-for="option in periods"
                    :key="option"
                    :href="periodHref(option)"
                    :aria-current="option === period ? 'page' : undefined"
                    :class="
                        cn(
                            'inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm whitespace-nowrap transition-colors',
                            'outline-none focus-visible:ring-3 focus-visible:ring-ring',
                            option === period
                                ? 'bg-card font-medium text-foreground shadow-raised'
                                : 'text-muted-foreground hover:text-foreground',
                        )
                    "
                >
                    <component :is="periodIcon(option)" class="size-4" aria-hidden="true" />
                    {{ PERIOD_LABEL[option] ?? option }}
                </Link>
            </nav>
        </template>

        <!-- Four links to the four plates, each carrying the bucket it was counted with. -->
        <nav aria-label="What is on my plate">
            <ul class="grid min-w-0 grid-cols-2 gap-4 xl:grid-cols-4">
                <li v-for="bucket in buckets" :key="bucket.key" class="min-w-0">
                    <StatCard
                        :label="bucket.label"
                        :value="bucket.count"
                        :sub="subFor(bucket.key, bucket.count)"
                        :icon="bucketIcon(bucket.key)"
                        :href="bucket.href"
                        size="compact"
                    />
                </li>
            </ul>
        </nav>

        <section class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <h2 class="text-base font-semibold tracking-tight">Work summary</h2>
                <p class="text-xs text-muted-foreground">{{ range.label }}</p>
            </div>

            <ul class="grid min-w-0 gap-4 sm:grid-cols-3">
                <li class="min-w-0">
                    <StatCard
                        label="Completed"
                        :value="summary.completed"
                        sub="Finished in this window"
                        :icon="CircleCheck"
                        size="compact"
                    />
                </li>
                <li class="min-w-0">
                    <StatCard
                        label="Due"
                        :value="summary.due"
                        sub="Falling in this window"
                        :icon="CalendarCheck"
                        size="compact"
                    />
                </li>
                <li class="min-w-0">
                    <!--
                        The word under the number is what says this one is late. Nothing here is
                        tinted: a red figure says nothing in greyscale and nothing to a screen
                        reader, and "Past their due date" says it in both (DESIGN.md §5.6).
                    -->
                    <StatCard
                        label="Overdue"
                        :value="summary.overdue"
                        sub="Past their due date"
                        :icon="CircleAlert"
                        size="compact"
                    />
                </li>
            </ul>
        </section>

        <!--
            Timer roles only. There is no `v-else` and there must not be one — see the note at
            the top of this file.
        -->
        <section v-if="time" class="flex min-w-0 flex-col gap-4">
            <h2 class="text-base font-semibold tracking-tight">Time</h2>

            <ul class="grid min-w-0 gap-4 sm:grid-cols-3">
                <li class="min-w-0">
                    <StatCard
                        label="Tracked"
                        :value="formatMinutes(time.tracked_minutes)"
                        sub="Counted towards this window"
                        :icon="Timer"
                        size="compact"
                    />
                </li>
                <li class="min-w-0">
                    <StatCard
                        label="Awaiting approval"
                        :value="formatMinutes(time.pending_minutes)"
                        sub="Not counted until it is decided"
                        :icon="Hourglass"
                        size="compact"
                    />
                </li>
                <li class="min-w-0">
                    <!-- A count of things somebody can open, so it opens them. -->
                    <StatCard
                        label="Entries"
                        :value="time.entries"
                        sub="Stopped in this window"
                        :icon="Clock"
                        :href="time.href"
                        size="compact"
                    />
                </li>
            </ul>
        </section>

        <!-- Office-clock roles only, on the same terms as the Time block above. -->
        <section v-if="clocked" class="flex min-w-0 flex-col gap-4">
            <h2 class="text-base font-semibold tracking-tight">Attendance</h2>

            <ul class="grid min-w-0 grid-cols-2 gap-4 sm:grid-cols-3">
                <li class="min-w-0">
                    <StatCard
                        label="Clocked"
                        :value="formatMinutes(clocked.minutes)"
                        sub="Worked in this window"
                        :icon="Clock"
                        size="compact"
                    />
                </li>
                <li class="min-w-0">
                    <StatCard
                        label="Days recorded"
                        :value="clocked.days"
                        sub="With a status on them"
                        :icon="CalendarCheck"
                        size="compact"
                    />
                </li>
            </ul>
        </section>
    </PageShell>
</template>
