<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ActivityDayPayload, ActivitySession } from '@/Components/Activity/activity';
import ActivityTimeline from '@/Components/Activity/ActivityTimeline.vue';
import SiteTable from '@/Components/Activity/SiteTable.vue';
import DateStepper from '@/Components/DateStepper.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import { formatDuration } from '@/Components/Timer/timer';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

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
    const date = new Date(iso);

    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

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

        <p class="text-sm tabular-nums">{{ summaryLine }}</p>

        <Card v-for="session in activity.sessions" :key="session.id" class="min-w-0 gap-4">
            <CardHeader class="min-w-0">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <CardTitle class="min-w-0 text-sm font-medium break-words">
                        {{ session.task?.name ?? 'No task' }}
                        <span v-if="session.project" class="font-normal text-muted-foreground">
                            · {{ session.project.name }}
                        </span>
                    </CardTitle>
                    <StatusBadge :status="STATE_STATUS[session.state]" :label="session.state_label" size="sm" />
                    <Badge v-if="!session.has_activity_data" variant="outline">No activity data</Badge>
                </div>
                <p class="text-xs text-muted-foreground tabular-nums">
                    {{ span(session) }} · {{ formatDuration(session.elapsed_seconds) }}
                </p>
            </CardHeader>
            <CardContent class="min-w-0">
                <ActivityTimeline :session="session" />
            </CardContent>
        </Card>

        <SiteTable :sites="activity.sites" />
    </div>
</template>
