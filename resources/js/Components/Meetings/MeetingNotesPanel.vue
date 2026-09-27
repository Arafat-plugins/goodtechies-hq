<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { NotebookPen } from '@lucide/vue';
import { useId, watch } from 'vue';
import type { MeetingDetail } from '@/Components/Meetings/meetingDetail';
import { meetingRoutes } from '@/Components/Meetings/meetings';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

/**
 * Notes and decisions — the pad (Part D §12: *"notes + decisions recorded"*).
 *
 * ## Two fields, one save, because it is one pad
 *
 * `MeetingService::recordNotes()` writes both in a single `updateOrCreate` behind a unique index
 * on `meeting_id`, so two people writing the same meeting up at the same moment end with one pad
 * and the later text rather than two pads and a coin toss. Two Save buttons would have implied
 * two records.
 *
 * ## Read-only for everybody else, rather than hidden
 *
 * Who may write is the `update` ability — the organiser or an Admin (slice 1's decision 7-15).
 * Everybody who can open the meeting **reads** what was written: a record of a meeting somebody
 * sat in is not a secret from them, and hiding it would make the page look empty to the people
 * the minutes are for. So the same content renders as prose with a line saying who may change
 * it, and no control the endpoint would refuse is drawn (DESIGN.md §5.11).
 *
 * Empty is a sentence, not a blank: *nothing has been written down yet* is a real state, and
 * clearing both fields and saving deletes the pad, which is how *"has this meeting been
 * minuted?"* stays a question the presence of a row answers.
 */

const props = defineProps<{ meeting: MeetingDetail }>();

const notesId = useId();
const decisionsId = useId();

const form = useForm({
    notes: props.meeting.notes ?? '',
    decisions: props.meeting.decisions ?? '',
});

// The page re-renders after every save and after every RSVP; the pad follows the server rather
// than holding whatever was last typed into it, so a second tab's edit is not silently kept.
watch(
    () => [props.meeting.notes, props.meeting.decisions],
    () => {
        if (!form.isDirty) {
            form.notes = props.meeting.notes ?? '';
            form.decisions = props.meeting.decisions ?? '';
        }
    },
);

function save(): void {
    form.put(meetingRoutes(props.meeting.id).notes, {
        preserveScroll: true,
        onSuccess: () => form.defaults({ notes: form.notes, decisions: form.decisions }),
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4 p-6">
        <div class="flex min-w-0 items-center gap-2">
            <NotebookPen class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <h2 class="text-sm font-medium">Notes and decisions</h2>
        </div>

        <form v-if="meeting.permissions.can_update" class="flex min-w-0 flex-col gap-4" @submit.prevent="save">
            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="notesId">Notes</Label>
                <Textarea
                    :id="notesId"
                    v-model="form.notes"
                    rows="5"
                    placeholder="What was said."
                    :aria-invalid="form.errors.notes ? true : undefined"
                />
                <p v-if="form.errors.notes" class="text-xs text-destructive">{{ form.errors.notes }}</p>
            </div>

            <div class="flex min-w-0 flex-col gap-2">
                <Label :for="decisionsId">Decisions</Label>
                <Textarea
                    :id="decisionsId"
                    v-model="form.decisions"
                    rows="3"
                    placeholder="What was settled."
                    :aria-invalid="form.errors.decisions ? true : undefined"
                />
                <p v-if="form.errors.decisions" class="text-xs text-destructive">{{ form.errors.decisions }}</p>
            </div>

            <div class="flex min-w-0 flex-wrap items-center gap-3">
                <Button type="submit" size="sm" :disabled="form.processing">Save notes</Button>
                <p class="text-xs text-muted-foreground">
                    Either field can be left empty. Clearing both removes the write-up.
                </p>
            </div>
        </form>

        <!-- Everybody else reads it. See the docblock: read-only, not hidden. -->
        <div v-else class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <h3 class="text-xs font-medium text-muted-foreground">Notes</h3>
                <p class="min-w-0 text-sm whitespace-pre-line" :class="meeting.notes ? undefined : 'text-muted-foreground'">
                    {{ meeting.notes ?? 'Nothing has been written down yet.' }}
                </p>
            </div>

            <div class="flex min-w-0 flex-col gap-1">
                <h3 class="text-xs font-medium text-muted-foreground">Decisions</h3>
                <p
                    class="min-w-0 text-sm whitespace-pre-line"
                    :class="meeting.decisions ? undefined : 'text-muted-foreground'"
                >
                    {{ meeting.decisions ?? 'Nothing settled has been recorded.' }}
                </p>
            </div>

            <p class="text-xs text-muted-foreground">
                The organiser and an administrator write this up.
            </p>
        </div>
    </Card>
</template>
