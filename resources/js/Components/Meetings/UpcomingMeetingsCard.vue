<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarClock } from '@lucide/vue';
import EmptyState from '@/Components/EmptyState.vue';
import MeetingJoinButton from '@/Components/Meetings/MeetingJoinButton.vue';
import type { Meeting } from '@/Components/Meetings/meetings';
import { meetingRoutes, meetingTimeRange, meetingsHref } from '@/Components/Meetings/meetings';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';

/**
 * "Upcoming meetings" — the card Part D §12 puts on both dashboards.
 *
 * One component, both surfaces, for the reason `UpcomingHolidaysCard` is one: it is the same
 * question with the same answer, and two copies would be two places for a Join control or a
 * state to be worded differently. What differs is only the **scope of the rows**, which is
 * `Meeting::visibleTo()`'s business on the server — an Admin sees the agency's diary and
 * everybody else sees the meetings they are actually in, and this file does not know which.
 *
 * ## Rows are `MeetingResource`, so the Join control is the same one
 *
 * `MeetingJoinButton` takes a whole `Meeting`, which is exactly what the dashboard controllers
 * send. That is the point of not inventing a card-shaped payload: the rules about a Join link —
 * `target="_blank"`, `rel="noopener noreferrer"`, an accessible name that names the meeting,
 * and nothing at all on a cancelled meeting — are written once and are the same here as on the
 * List and on the detail page.
 *
 * ## What it deliberately does not print
 *
 * The linked project. It is on the payload and correctly scoped per viewer, and there is no
 * room for it on a five-line card: somebody can be in a meeting about a project they are not
 * on, so half a line of project is the kind of abbreviation that turns into a leak. Time,
 * title, state and a way in is the whole card.
 *
 * ## Each row says which day, not only which hour
 *
 * Five rows can span a week, and `15:00 – 16:00` on its own does not say whether that is this
 * afternoon or next Thursday — which is the one question a diary card is opened to answer. The
 * day is formatted from the **same `Date`** `meetingTimeRange()` reads, so the two halves of a
 * row cannot end up in different clocks.
 *
 * The formatter is local rather than added to `meetings.ts`: that module belongs to the other
 * Phase 7 slice, and nothing outside this card wants a three-letter weekday.
 *
 * **Zero is an answer.** An empty card says the diary is clear, and offers the way to fill it.
 */

defineProps<{ meetings: Meeting[] }>();

const DAY = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });

/** `Sat 26 Sep`, or an em dash when there is no time to read. */
function dayOf(iso: string | null): string {
    if (iso === null || iso === '') {
        return '—';
    }

    const at = new Date(iso);

    return Number.isNaN(at.getTime()) ? '—' : DAY.format(at);
}
</script>

<template>
    <Card class="min-w-0 gap-4 p-6">
        <div class="flex min-w-0 items-center justify-between gap-4">
            <h2 class="text-sm font-medium">Upcoming meetings</h2>
            <Link
                v-if="meetings.length > 0"
                :href="meetingsHref()"
                class="shrink-0 text-xs text-muted-foreground underline-offset-4 hover:underline"
            >
                See all
            </Link>
        </div>

        <EmptyState
            v-if="meetings.length === 0"
            :icon="CalendarClock"
            title="Nothing in the diary"
            description="No meeting you are in is coming up. One you are invited to appears here as soon as it is scheduled."
        >
            <template #action>
                <Button as-child size="sm" variant="outline">
                    <Link :href="meetingsHref()">Open Meetings</Link>
                </Button>
            </template>
        </EmptyState>

        <ul v-else class="flex min-w-0 flex-col divide-y">
            <li
                v-for="meeting in meetings"
                :key="meeting.id"
                class="flex min-w-0 flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:gap-4"
            >
                <div class="flex shrink-0 flex-row gap-2 sm:w-32 sm:flex-col sm:gap-0.5">
                    <p class="text-xs font-medium">{{ dayOf(meeting.start_at) }}</p>
                    <p class="text-xs tabular-nums text-muted-foreground">
                        {{ meetingTimeRange(meeting.start_at, meeting.end_at) }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                    <Link
                        :href="meetingRoutes(meeting.id).show"
                        class="min-w-0 truncate rounded-sm text-sm font-medium outline-none hover:underline focus-visible:ring-3 focus-visible:ring-ring"
                    >
                        {{ meeting.title }}
                    </Link>
                    <StatusBadge :status="meeting.state" :label="meeting.state_label" size="sm" />
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <MeetingJoinButton :meeting="meeting" />
                </div>
            </li>
        </ul>
    </Card>
</template>
