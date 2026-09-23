<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    FolderKanban,
    Inbox,
    ListChecks,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import HoursBreakdown from '@/Components/Time/HoursBreakdown.vue';
import RejectEntryDialog from '@/Components/Time/RejectEntryDialog.vue';
import TimeEntryRow from '@/Components/Time/TimeEntryRow.vue';
import type { AdminTimeEntry, TimeBreakdownRow, TimeDateNav, TimeWeek } from '@/Components/Time/time';
import { adminTimeRoutes } from '@/Components/Time/time';
import { formatDuration } from '@/Components/Timer/timer';
import EmptyState from '@/Components/EmptyState.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/**
 * Admin → Workforce → Time: the approval queue, and hours today and this week.
 *
 * ## The queue leads, because it is the only thing here that is stuck
 *
 * `manual_time_requires_approval` is on, so a manual or corrected entry counts toward nothing
 * until somebody signs it off — decision 4-16, and the reason this screen exists. Everything
 * below the queue is a number to read; the queue is a number of decisions to make, so it is
 * first and the breakdowns are under it.
 *
 * The rows are **oldest first**. Not biggest, not by person, not by how far over a target
 * somebody is: the entry that has been waiting longest is the one holding up a record, and any
 * other ordering of a list of people's hours would be a ranking, which Part H forbids.
 *
 * ## Zero is an answer
 *
 * An empty queue says *nothing is waiting* rather than rendering nothing, and an employee who
 * tracked nothing today is in the table at 0m rather than missing from it. A screen whose rows
 * come and go through the week is one nobody can compare against yesterday.
 *
 * ## Nothing here decides anything
 *
 * Approve and Turn down are drawn from `entry.permissions.can_decide`, which is
 * `TimeEntryPolicy::approve` resolved per record on the server, and both endpoints check again
 * (decisions 2-28, 2-31).
 */

const props = defineProps<{
    date: TimeDateNav;
    week: TimeWeek;
    /** Waiting on a decision, oldest first. */
    queue: AdminTimeEntry[];
    /** The whole count, which the bounded list above may have cut off. */
    queue_total: number;
    queue_limit: number;
    /** Flagged by the watchdog and already counted — a look, not a decision. */
    flagged: AdminTimeEntry[];
    /** What has been ruled on lately, so a refusal is visible rather than a row that vanished. */
    decided: AdminTimeEntry[];
    byEmployee: TimeBreakdownRow[];
    byProject: TimeBreakdownRow[];
    byTask: TimeBreakdownRow[];
}>();

const rejecting = ref<AdminTimeEntry | null>(null);
const rejectOpen = ref(false);

function reject(entry: AdminTimeEntry): void {
    rejecting.value = entry;
    rejectOpen.value = true;
}

/** How much time is sitting in the queue, so the cost of not clearing it is a number. */
const waitingSeconds = computed(() =>
    props.queue.reduce((total, entry) => total + (entry.duration_seconds ?? 0), 0),
);

/** True when the queue ran past its limit, which the screen then says out loud. */
const truncated = computed(() => props.queue_total > props.queue.length);
</script>

<template>
    <Head title="Time" />

    <PageShell
        title="Time"
        :description="`${date.label} · week of ${week.label}`"
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Time' }]"
    >
        <template #actions>
            <div class="flex items-center gap-2">
                <Button as-child variant="outline" size="icon">
                    <Link :href="adminTimeRoutes.index(date.previous)" preserve-scroll>
                        <ChevronLeft class="size-4" aria-hidden="true" />
                        <span class="sr-only">Previous day</span>
                    </Link>
                </Button>
                <Button v-if="date.value !== date.today" as-child variant="outline">
                    <Link :href="adminTimeRoutes.index()">Today</Link>
                </Button>
                <Button as-child variant="outline" size="icon">
                    <Link :href="adminTimeRoutes.index(date.next)" preserve-scroll>
                        <ChevronRight class="size-4" aria-hidden="true" />
                        <span class="sr-only">Next day</span>
                    </Link>
                </Button>
            </div>
        </template>

        <!-- The queue. First, because it is the only thing on this page that is waiting on somebody. -->
        <section aria-labelledby="approval-queue" class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="approval-queue" class="text-base font-semibold tracking-tight">Waiting for approval</h2>
                <p class="text-sm text-muted-foreground">
                    {{ queue_total }} {{ queue_total === 1 ? 'entry' : 'entries' }}
                    <template v-if="waitingSeconds > 0"> · {{ formatDuration(waitingSeconds) }} not counted yet</template>
                </p>
            </div>

            <Card v-if="!queue.length" class="p-6">
                <EmptyState
                    :icon="CircleCheck"
                    title="Nothing is waiting"
                    description="Every recorded entry has been signed off. Hours added by hand or corrected after the fact arrive here."
                />
            </Card>

            <template v-else>
                <ul class="flex flex-col gap-2">
                    <TimeEntryRow v-for="entry in queue" :key="entry.id" :entry="entry" @reject="reject" />
                </ul>
                <p v-if="truncated" class="text-xs text-muted-foreground">
                    Showing the {{ queue_limit }} that have been waiting longest, of {{ queue_total }}.
                </p>
            </template>
        </section>

        <!--
            Flagged and already counted. A separate list on purpose: the timer signs its own
            entries off at stop, so one it flagged is already in somebody's total and is not
            waiting for anybody — but it carries a sentence saying the hours may not be right.
            There is nothing to approve here, only hours to take back out.
        -->
        <section v-if="flagged.length" aria-labelledby="flagged-entries" class="flex min-w-0 flex-col gap-4">
            <h2 id="flagged-entries" class="text-base font-semibold tracking-tight">
                Flagged by the timer, already counted
            </h2>
            <p class="text-sm text-muted-foreground">
                The watchdog stopped or paused these and they count as they stand. Read the reason before this week is
                signed off.
            </p>
            <ul class="flex flex-col gap-2">
                <TimeEntryRow
                    v-for="entry in flagged"
                    :key="entry.id"
                    :entry="entry"
                    :show-approve="false"
                    @reject="reject"
                />
            </ul>
        </section>

        <!-- Hours today and this week. Three groupings of one query, each scoped to the viewer. -->
        <section aria-labelledby="hours" class="flex min-w-0 flex-col gap-4">
            <h2 id="hours" class="text-base font-semibold tracking-tight">Hours today and this week</h2>
            <div class="grid min-w-0 gap-4 lg:grid-cols-3">
                <HoursBreakdown title="By employee" noun="employee" :icon="Users" :rows="byEmployee" />
                <HoursBreakdown title="By project" noun="project" :icon="FolderKanban" :rows="byProject" />
                <HoursBreakdown title="By task" noun="task" :icon="ListChecks" :rows="byTask" />
            </div>
            <p class="text-xs text-muted-foreground">
                A total counts an entry once it has been approved. Anything still waiting is shown beside the total and
                is not in it.
            </p>
        </section>

        <!--
            Recently decided. It is here because a refusal is not a deletion: the Admin who made
            one has to be able to see what they turned down and why, and to change their mind.
        -->
        <section v-if="decided.length" aria-labelledby="decided" class="flex min-w-0 flex-col gap-4">
            <h2 id="decided" class="text-base font-semibold tracking-tight">Recently decided</h2>
            <ul class="flex flex-col gap-2">
                <TimeEntryRow v-for="entry in decided" :key="entry.id" :entry="entry" @reject="reject" />
            </ul>
        </section>

        <Card v-if="!queue.length && !flagged.length && !decided.length && !byEmployee.length" class="p-6">
            <EmptyState
                :icon="Inbox"
                title="No time has been tracked yet"
                description="Entries appear here as soon as somebody runs the timer or adds a stretch of time by hand."
            />
        </Card>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                Approving and turning down are both recorded in the audit log with the old and new values. These hours
                are what payroll will read.
            </span>
        </p>

        <RejectEntryDialog v-model:open="rejectOpen" :entry="rejecting" />
    </PageShell>
</template>
