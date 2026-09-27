<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Clock, FolderKanban, ListTodo, Pencil, User } from '@lucide/vue';
import { computed } from 'vue';
import CancelMeetingDialog from '@/Components/Meetings/CancelMeetingDialog.vue';
import MeetingActionItemsPanel from '@/Components/Meetings/MeetingActionItemsPanel.vue';
import MeetingJoinButton from '@/Components/Meetings/MeetingJoinButton.vue';
import MeetingNotesPanel from '@/Components/Meetings/MeetingNotesPanel.vue';
import MeetingParticipantsPanel from '@/Components/Meetings/MeetingParticipantsPanel.vue';
import type { MeetingAssignee, MeetingDetail } from '@/Components/Meetings/meetingDetail';
import {
    FULL_DATE,
    meetingDuration,
    meetingRoutes,
    meetingTimeRange,
    meetingsHref,
} from '@/Components/Meetings/meetings';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * One meeting: when it is, who is in it, a way in, what was said, what was settled, and what
 * somebody agreed to do about it (master prompt Part D §12, Phase 7).
 *
 * ## One page, every surface
 *
 * The layout comes from `auth.user.surface`, exactly as `Pages/Shared/Messages.vue` does: whose
 * meeting this is belongs to the person and not to the shell they are in, and three copies of
 * this screen would be three places for a Join control or a cancel confirmation to drift.
 * `AccountantLayout` is in the list for completeness and is unreachable — the route group is
 * gated on `meetings.use` and the Accountant holds none of it.
 *
 * ## Every control is the server's answer, never a role read in Vue
 *
 * `permissions.can_update`, `can_cancel`, `can_rsvp` and `can_convert_action_items` all come
 * from the policy, resolved per requester, and the endpoints ask the same policy again before
 * they act. Nothing here compares a role, and nothing is drawn that the endpoint would refuse
 * (DESIGN.md §5.11).
 *
 * That is also what makes a cancelled meeting read-only without this file saying so anywhere:
 * `MeetingPolicy` answers false to `update`, `cancel` and `rsvp` on one, the controller answers
 * false to `can_convert_action_items`, and `MeetingJoinButton` draws nothing for a cancelled
 * meeting because the room being open is not a reason to go to it. The tasks it already
 * produced stay — they are on the payload and are rendered whatever state the meeting is in.
 *
 * ## The linked project and task are printed only when they are in the payload
 *
 * `meeting.project` and `meeting.task` are **absent** — not null — for a viewer who may not see
 * them, because somebody can be in a meeting about work they are not on. `v-if` is the whole of
 * the rule, and there is deliberately no "private" placeholder: a line saying that would leak
 * the one bit being withheld.
 */

const props = defineProps<{
    meeting: MeetingDetail;
    assignable: MeetingAssignee[];
}>();

defineOptions({
    layout: (page: SharedProps) => {
        const surface = page.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

/** `Monday 21 September 2026`, from the ISO the server sent. */
const day = computed(() => {
    if (props.meeting.start_at === null) {
        return '—';
    }

    const at = new Date(props.meeting.start_at);

    return Number.isNaN(at.getTime()) ? '—' : FULL_DATE.format(at);
});
</script>

<template>
    <Head :title="meeting.title" />

    <PageShell
        :title="meeting.title"
        :breadcrumb="[{ label: 'Meetings', href: meetingsHref() }, { label: meeting.title }]"
    >
        <template #actions>
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <MeetingJoinButton :meeting="meeting" />

                <Button v-if="meeting.permissions.can_update" as-child variant="outline" size="sm">
                    <Link :href="meetingRoutes(meeting.id).edit">
                        <Pencil aria-hidden="true" />
                        Edit
                    </Link>
                </Button>

                <CancelMeetingDialog v-if="meeting.permissions.can_cancel" :meeting="meeting" />
            </div>
        </template>

        <!-- When, what state, who called it, and what it is about. -->
        <Card class="flex min-w-0 flex-col gap-4 p-6">
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                <StatusBadge :status="meeting.state" :label="meeting.state_label" />
                <span v-if="meeting.state === 'cancelled'" class="text-xs text-muted-foreground">
                    This meeting was called off. It is kept as a record and cannot be changed.
                </span>
            </div>

            <dl class="grid min-w-0 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <CalendarDays class="size-3.5 shrink-0" aria-hidden="true" />
                        Date
                    </dt>
                    <dd class="min-w-0 text-sm font-medium">{{ day }}</dd>
                </div>

                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Clock class="size-3.5 shrink-0" aria-hidden="true" />
                        Time
                    </dt>
                    <dd class="min-w-0 text-sm font-medium tabular-nums">
                        {{ meetingTimeRange(meeting.start_at, meeting.end_at) }}
                        <span class="font-normal text-muted-foreground">
                            · {{ meetingDuration(meeting.duration_minutes) }}
                        </span>
                    </dd>
                </div>

                <div class="flex min-w-0 flex-col gap-1">
                    <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <User class="size-3.5 shrink-0" aria-hidden="true" />
                        Organiser
                    </dt>
                    <dd class="min-w-0 truncate text-sm font-medium">
                        {{ meeting.is_organizer ? `${meeting.organizer.name} (you)` : meeting.organizer.name }}
                    </dd>
                </div>

                <!-- Present only when this viewer may see it. See the docblock. -->
                <div v-if="meeting.project" class="flex min-w-0 flex-col gap-1">
                    <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <FolderKanban class="size-3.5 shrink-0" aria-hidden="true" />
                        Project
                    </dt>
                    <dd class="min-w-0 truncate text-sm font-medium">{{ meeting.project.name }}</dd>
                </div>

                <div v-if="meeting.task" class="flex min-w-0 flex-col gap-1">
                    <dt class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <ListTodo class="size-3.5 shrink-0" aria-hidden="true" />
                        Task
                    </dt>
                    <dd class="min-w-0 truncate text-sm font-medium">{{ meeting.task.title }}</dd>
                </div>
            </dl>

            <div v-if="meeting.agenda" class="flex min-w-0 flex-col gap-1 border-t pt-4">
                <h2 class="text-xs text-muted-foreground">Agenda</h2>
                <p class="min-w-0 text-sm whitespace-pre-line">{{ meeting.agenda }}</p>
            </div>
        </Card>

        <div class="grid min-w-0 items-start gap-4 lg:grid-cols-3">
            <!--
                The write-up and what came out of it lead, because they are what somebody opens
                a meeting that has happened to find. The room sits beside them on a wide screen
                and after them on a phone, where the order IS the priority.
            -->
            <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
                <MeetingNotesPanel :meeting="meeting" />
                <MeetingActionItemsPanel :meeting="meeting" :assignable="assignable" />
            </div>

            <MeetingParticipantsPanel :meeting="meeting" />
        </div>
    </PageShell>
</template>
