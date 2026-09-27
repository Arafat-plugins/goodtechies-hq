<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ListChecks } from '@lucide/vue';
import { useId } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { MeetingAssignee, MeetingDetail } from '@/Components/Meetings/meetingDetail';
import { meetingRoutes } from '@/Components/Meetings/meetings';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/Components/ui/native-select';

/**
 * Action items — which is to say, tasks (Part D §12: *"action items → **Convert to Task**"*).
 *
 * ## One control, because there is only one thing to do
 *
 * There is no `meeting_action_items` table and there must not be one: an action item *becomes*
 * a task, and the task is where it lives. A holding list of items waiting to be converted would
 * be a second to-do list, invisible to the board, My Tasks and every report. So "add" and
 * "convert" are the same button — you type what somebody agreed to do, say who and by when, and
 * a task exists, carrying `source_meeting_id` back to this meeting.
 *
 * The three fields are exactly what `MeetingService::convertActionItem()` takes on top of what
 * it forces: the project is the meeting's and the provenance is the meeting's, so neither is
 * asked for. Everything else about the task — description, priority, a second assignee — is on
 * the task, one click away through the row this creates.
 *
 * ## When the form is not drawn
 *
 * `can_convert_action_items` is the server's answer, and it folds in three things: the meeting
 * is not cancelled, this person may create tasks, and there is a linked project **they can
 * see**. `action_item_note` is the sentence to print instead of the form, and it is the
 * server's too — deliberately, because *which* refusals may share wording is a privacy
 * judgement. Only the last two do: a participant who may not see the project is told the same
 * thing as somebody on a meeting with no project at all, because the difference between those
 * two is the fact being withheld. A cancelled meeting and "you do not create tasks here" each
 * say what they are, since neither reveals anything the reader did not already have.
 *
 * Rows already converted stay whatever happens: a cancelled meeting keeps its tasks.
 */

const props = defineProps<{
    meeting: MeetingDetail;
    assignable: MeetingAssignee[];
}>();

const titleId = useId();
const assigneeId = useId();
const dueId = useId();

const form = useForm<{ title: string; assignee_id: number | null; due_date: string }>({
    title: '',
    assignee_id: null,
    due_date: '',
});

function convert(): void {
    form.transform((data) => ({
        title: data.title,
        assignee_id: data.assignee_id === null ? null : Number(data.assignee_id),
        due_date: data.due_date === '' ? null : data.due_date,
    })).post(meetingRoutes(props.meeting.id).actionItems, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4 p-6">
        <div class="flex min-w-0 items-center gap-2">
            <ListChecks class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <h2 class="text-sm font-medium">
                Action items
                <span class="font-normal text-muted-foreground">({{ meeting.action_items.length }})</span>
            </h2>
        </div>

        <ul v-if="meeting.action_items.length > 0" class="flex min-w-0 flex-col divide-y">
            <li
                v-for="item in meeting.action_items"
                :key="item.id"
                class="flex min-w-0 flex-col gap-1.5 py-3 first:pt-0 last:pb-0"
            >
                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <!--
                        The task itself. A link when this surface has a task screen, plain text
                        when it does not — a link to a route that does not exist is a 500 on a
                        page somebody was only glancing at.
                    -->
                    <Link
                        v-if="item.href"
                        :href="item.href"
                        class="min-w-0 truncate rounded-sm text-sm font-medium outline-none hover:underline focus-visible:ring-3 focus-visible:ring-ring"
                    >
                        {{ item.title }}
                    </Link>
                    <span v-else class="min-w-0 truncate text-sm font-medium">{{ item.title }}</span>

                    <StatusBadge
                        v-if="item.status_tone && item.status_label"
                        :status="item.status_tone"
                        :label="item.status_label"
                        size="sm"
                    />
                </div>

                <p class="flex min-w-0 flex-wrap gap-x-4 gap-y-0.5 text-xs text-muted-foreground">
                    <span class="min-w-0 truncate">
                        {{
                            item.assignees.length > 0
                                ? item.assignees.map((person) => person.name).join(', ')
                                : 'Nobody assigned'
                        }}
                    </span>
                    <span v-if="item.due_date_label" class="shrink-0">Due {{ item.due_date_label }}</span>
                </p>
            </li>
        </ul>

        <EmptyState
            v-else
            :icon="ListChecks"
            title="Nothing was agreed yet"
            description="An action item becomes a task straight away — it keeps a link back to this meeting and shows up on the board and in My Tasks like any other."
        />

        <form
            v-if="meeting.can_convert_action_items"
            class="flex min-w-0 flex-col gap-4 border-t pt-4"
            @submit.prevent="convert"
        >
            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="titleId">What was agreed</Label>
                <Input
                    :id="titleId"
                    v-model="form.title"
                    required
                    maxlength="255"
                    placeholder="Rewrite the service-page titles"
                    :aria-invalid="form.errors.title ? true : undefined"
                />
                <p v-if="form.errors.title" class="text-xs text-destructive">{{ form.errors.title }}</p>
            </div>

            <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="assigneeId">Assignee</Label>
                    <NativeSelect :id="assigneeId" v-model="form.assignee_id">
                        <NativeSelectOption :value="null">Nobody yet</NativeSelectOption>
                        <NativeSelectOption v-for="person in assignable" :key="person.id" :value="person.id">
                            {{ person.name }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <p v-if="form.errors.assignee_id" class="text-xs text-destructive">
                        {{ form.errors.assignee_id }}
                    </p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label :for="dueId">Due date</Label>
                    <Input :id="dueId" v-model="form.due_date" type="date" />
                    <p v-if="form.errors.due_date" class="text-xs text-destructive">{{ form.errors.due_date }}</p>
                </div>
            </div>

            <div class="flex min-w-0 flex-wrap items-center gap-3">
                <Button type="submit" size="sm" :disabled="form.processing">Convert to task</Button>
                <p class="text-xs text-muted-foreground">
                    It becomes a To&nbsp;do task on this meeting's project.
                </p>
            </div>
        </form>

        <!--
            No form, and the server's own sentence for why. Not composed here: only two of the
            three refusals may share wording (see the docblock), and which two is a privacy
            judgement that belongs on the server beside the policy calls that made it.
        -->
        <p v-else-if="meeting.action_item_note" class="border-t pt-4 text-xs text-muted-foreground">
            {{ meeting.action_item_note }}
        </p>
    </Card>
</template>
