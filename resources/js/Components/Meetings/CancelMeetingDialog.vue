<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarX2, Video } from '@lucide/vue';
import { ref } from 'vue';
import type { MeetingDetail } from '@/Components/Meetings/meetingDetail';
import { meetingRoutes } from '@/Components/Meetings/meetings';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';

/**
 * Calling a meeting off, with the consequences written down before the button is pressed.
 *
 * ## The dialog says what will happen, in the order it matters
 *
 * Part D §12 promises three things and the dialog states all three rather than leaving any of
 * them to be discovered afterwards:
 *
 *   1. **participants are notified** — eleven people get a bell, so it is not a quiet act;
 *   2. **linked tasks are not deleted** — the fear that stops people cancelling a meeting whose
 *      action items are half done, and the guarantee `MeetingService::cancel()` makes by never
 *      touching a task at all;
 *   3. **with the manual driver, the Meet room stays open until a human closes it.**
 *
 * The third is the reason this component takes `calendar.cancel_notice` rather than composing a
 * sentence. `MeetingService::cancel()` writes exactly that string to the activity trail; a trail
 * is a record, not a warning, and the person who has to go and close the room is the one
 * looking at this dialog. So it is said here too, in the same words, from the same constant —
 * `ManualLink::CANCEL_INSTRUCTION`. It is absent when there is no link, because a meeting
 * nobody pasted a link into has no room standing open and telling its organiser to close one
 * would be an instruction to do nothing.
 *
 * ## Focus comes back to the button that opened it
 *
 * The trigger is a `DialogTrigger` **inside this component**, so reka restores focus to an
 * element that is still mounted when the dialog closes. That is deliberately not the
 * "menu item opens a confirm dialog" pattern, which drops focus to `<body>` app-wide because
 * the menu has already unmounted the item reka is trying to return to (decision 5-20). This
 * component does not inherit that bug because it does not use that pattern.
 *
 * ## The two buttons are not "Cancel" and "Cancel"
 *
 * Dismissing reads *Keep the meeting*. In a dialog about cancelling something, a button
 * labelled *Cancel* is genuinely ambiguous, and this is the one dialog in the application where
 * pressing the wrong one sends notifications to everybody.
 */

const props = defineProps<{ meeting: MeetingDetail }>();

const open = ref(false);

const form = useForm({});

function confirm(): void {
    form.post(meetingRoutes(props.meeting.id).cancel, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" size="sm">
                <CalendarX2 aria-hidden="true" />
                Cancel meeting
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Cancel this meeting?</DialogTitle>
                <DialogDescription>“{{ meeting.title }}” will be marked Cancelled.</DialogDescription>
            </DialogHeader>

            <ul class="flex min-w-0 flex-col gap-2 text-sm text-muted-foreground">
                <li>Everybody in the room is notified.</li>
                <li>
                    The tasks this meeting produced are
                    <span class="font-medium text-foreground">not deleted</span>. They keep their link back to it.
                </li>
                <li>
                    The meeting stays on the calendar, marked Cancelled. It cannot be edited or brought back — to have
                    it again, schedule it.
                </li>
            </ul>

            <!--
                Manual driver only, and only when there is a room to close. The same sentence
                the activity trail gets, from the same constant.
            -->
            <p
                v-if="meeting.calendar.cancel_notice"
                class="flex min-w-0 items-start gap-2 rounded-md border border-status-waiting-border bg-status-waiting-bg p-3 text-xs text-status-waiting-fg"
            >
                <Video class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>{{ meeting.calendar.cancel_notice }}</span>
            </p>

            <DialogFooter>
                <Button type="button" variant="outline" @click="open = false">Keep the meeting</Button>
                <Button type="button" variant="destructive" :disabled="form.processing" @click="confirm">
                    Cancel the meeting
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
