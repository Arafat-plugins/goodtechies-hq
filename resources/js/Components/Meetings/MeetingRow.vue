<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FolderKanban, Users } from '@lucide/vue';
import MeetingJoinButton from '@/Components/Meetings/MeetingJoinButton.vue';
import type { Meeting } from '@/Components/Meetings/meetings';
import { meetingDuration, meetingRoutes, meetingTimeRange } from '@/Components/Meetings/meetings';
import StatusBadge from '@/Components/StatusBadge.vue';

/**
 * One meeting, as the List draws it: when, what, what state it is in, who called it, how many
 * are in the room, what it is about, and a way in.
 *
 * ## The linked project is printed only when it is in the payload
 *
 * `meeting.project` is **optional**, because `MeetingService::linkedContextFor()` omits it for a
 * viewer who may not see the project — somebody can be in a meeting about work they are not on.
 * `v-if="meeting.project"` is therefore the whole of the rule here, and there is deliberately
 * no "Private project" placeholder: a row saying that would leak the one bit it is hiding.
 *
 * ## No state by colour
 *
 * `StatusBadge` is given the server's `state` and the server's `state_label`, so a cancelled
 * meeting reads *Cancelled* in words. The title is struck through as well — two carriers, and
 * neither of them hue on its own (DESIGN.md §5.6).
 *
 * The title is the only link. The row is not clickable as a whole: a row-level click handler
 * would swallow the Join control sitting inside it, and a `<div>` that navigates is not
 * something a keyboard can reach.
 */

defineProps<{ meeting: Meeting }>();
</script>

<template>
    <li
        class="flex min-w-0 flex-col gap-2 rounded-lg border bg-card p-3 sm:flex-row sm:items-start sm:gap-4 sm:p-4"
    >
        <!-- The time. On a phone it sits above the title; from `sm` it is a fixed left column. -->
        <p class="shrink-0 text-sm font-medium tabular-nums sm:w-36">
            {{ meetingTimeRange(meeting.start_at, meeting.end_at) }}
            <span class="block text-xs font-normal text-muted-foreground">
                {{ meetingDuration(meeting.duration_minutes) }}
            </span>
        </p>

        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                <Link
                    :href="meetingRoutes(meeting.id).show"
                    class="min-w-0 truncate text-sm font-medium outline-none hover:underline focus-visible:ring-3 focus-visible:ring-ring rounded-sm"
                    :class="meeting.state === 'cancelled' ? 'line-through decoration-1' : undefined"
                >
                    {{ meeting.title }}
                </Link>
                <StatusBadge :status="meeting.state" :label="meeting.state_label" size="sm" />
            </div>

            <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <span class="min-w-0 truncate">
                    {{ meeting.is_organizer ? 'You called this' : `Called by ${meeting.organizer.name}` }}
                </span>

                <span class="inline-flex shrink-0 items-center gap-1">
                    <Users class="size-3.5" aria-hidden="true" />
                    {{ meeting.participants.length }}
                    <span class="sr-only">
                        {{ meeting.participants.length === 1 ? 'person invited' : 'people invited' }}
                    </span>
                    <span aria-hidden="true">{{ meeting.participants.length === 1 ? 'person' : 'people' }}</span>
                </span>

                <!-- Present only when this viewer may see it. See the docblock. -->
                <span v-if="meeting.project" class="inline-flex min-w-0 items-center gap-1">
                    <FolderKanban class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="sr-only">Project:</span>
                    <span class="min-w-0 truncate">{{ meeting.project.name }}</span>
                </span>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <MeetingJoinButton :meeting="meeting" />
        </div>
    </li>
</template>
