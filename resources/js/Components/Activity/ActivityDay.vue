<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ActivityDayPayload, ActivitySession } from '@/Components/Activity/activity';
import ActivityDayTimeline from '@/Components/Activity/ActivityDayTimeline.vue';
import ActivityMonthCalendar from '@/Components/Activity/ActivityMonthCalendar.vue';
import SiteTable from '@/Components/Activity/SiteTable.vue';
import DateStepper from '@/Components/DateStepper.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import { formatDuration } from '@/Components/Timer/timer';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

/**
 * One person's day: the totals in one line, a card per timer session with its minute bar, and
 * the website table. Shared by "My activity" and the Admin's activity page.
 */
const props = defineProps<{
    activity: ActivityDayPayload;
}>();

const page = usePage();

/** The same page on another day: this page's path with `?date=`. */
function dayHref(date?: string): string {
    const path = page.url.split('?')[0];

    return date ? `${path}?date=${date}` : path;
}

const summaryLine = computed(() => {
    const s = props.activity.summary;

    return [
        `${formatDuration(s.tracked_seconds)} tracked`,
        `${formatDuration(s.active_minutes * 60)} active`,
        `${formatDuration(s.media_minutes * 60)} video or audio`,
        `${formatDuration(s.call_minutes * 60)} call`,
        `${formatDuration(s.idle_minutes * 60)} idle (${s.idle_percent} %)`,
    ].join(' · ');
});

function clock(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
}

/** This page's path, for the calendar's `?date=` links. */
const path = computed(() => page.url.split('?')[0]);

function span(session: ActivitySession): string {
    return `${clock(session.started_at)} – ${session.ended_at ? clock(session.ended_at) : 'now'}`;
}

const STATE_STATUS: Record<ActivitySession['state'], StatusKey> = {
    running: 'progress',
    paused: 'changes',
    stopped: 'done',
};
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <DateStepper
            :label="activity.date.label"
            :previous-href="dayHref(activity.date.previous)"
            :next-href="dayHref(activity.date.next)"
            :today-href="activity.date.value !== activity.date.today ? dayHref() : null"
        />

        <!-- Polish 031: one day, one line — and the month beside it as a calendar. -->
        <div class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="flex min-w-0 flex-col gap-4">
                <Card class="min-w-0 gap-4">
                    <CardHeader class="min-w-0">
                        <CardTitle class="text-sm font-medium">The day</CardTitle>
                        <p class="text-xs text-muted-foreground tabular-nums">{{ summaryLine }}</p>
                    </CardHeader>
                    <CardContent class="flex min-w-0 flex-col gap-4">
                        <ActivityDayTimeline v-if="activity.sessions.length" :sessions="activity.sessions" />
                        <p v-else class="py-6 text-center text-sm text-muted-foreground">Nothing was tracked on this day.</p>

                        <Table v-if="activity.sessions.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="text-xs text-muted-foreground uppercase">Time</TableHead>
                                    <TableHead class="text-xs text-muted-foreground uppercase">Task</TableHead>
                                    <TableHead class="text-right text-xs text-muted-foreground uppercase">Length</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="session in activity.sessions" :key="session.id">
                                    <TableCell class="whitespace-nowrap tabular-nums">{{ span(session) }}</TableCell>
                                    <TableCell class="min-w-0">
                                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                                            <span class="font-medium break-words">{{ session.task?.name ?? 'No task' }}</span>
                                            <span v-if="session.project" class="text-muted-foreground">{{ session.project.name }}</span>
                                            <StatusBadge :status="STATE_STATUS[session.state]" :label="session.state_label" size="sm" />
                                            <Badge v-if="!session.has_activity_data" variant="outline">No activity data</Badge>
                                        </div>
                                    </TableCell>
                                    <TableCell class="text-right tabular-nums">{{ formatDuration(session.elapsed_seconds) }}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <SiteTable :sites="activity.sites" :hidden="activity.sites_hidden" />
            </div>

            <ActivityMonthCalendar
                :month="activity.month"
                :selected="activity.date.value"
                :today="activity.date.today"
                :path="path"
            />
        </div>
    </div>
</template>
