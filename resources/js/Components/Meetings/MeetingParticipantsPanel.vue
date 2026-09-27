<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Users } from '@lucide/vue';
import { computed } from 'vue';
import type { MeetingDetail } from '@/Components/Meetings/meetingDetail';
import { RSVP_CHOICES } from '@/Components/Meetings/meetingDetail';
import type { MeetingRsvp } from '@/Components/Meetings/meetings';
import { RSVP_LABEL, RSVP_TONE, meetingRoutes } from '@/Components/Meetings/meetings';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';

/**
 * Who is in the room, what each of them said, and this viewer's own answer.
 *
 * ## Somebody who is not a participant has no control at all
 *
 * Not a disabled one. An Admin may edit and cancel anybody's meeting and may **not** answer for
 * them; nobody may. `permissions.can_rsvp` is the server's answer to "may this person answer
 * for themselves, on this meeting, right now" — it is false for a non-participant and false on
 * a cancelled meeting — and a greyed-out row of buttons would suggest there is a state in which
 * they light up. There is not, so they are not drawn.
 *
 * ## The answer is a word, never a colour
 *
 * Every attendee's state prints through `StatusBadge` with `RSVP_LABEL` beside the tone, so
 * *Going* and *Not going* are legible in greyscale and to a screen reader (DESIGN.md §5.6, and
 * §1.4's measurement of how close two of the eight status colours sit under deuteranopia).
 *
 * ## Which answer is current is said three ways, and none of them is brand orange
 *
 * The chosen button carries `aria-pressed="true"`, a tick, and a `secondary` fill. It is a
 * toggle group in behaviour, so it says so in the accessibility tree first; the tick is what
 * carries it in greyscale; the fill is only the third.
 *
 * It was `variant="default"` — a primary orange fill — and that was wrong twice over. DESIGN.md
 * §1.1: the mark spends orange on one dot out of a whole logo, and so does the app, so three
 * oranges on one screen means two of them are wrong; this shell already spends it on the active
 * nav rail and on the timer's Start. And the thing being marked would have been *Undecided*,
 * which is emphatically not the one thing that matters on this page.
 *
 * Posting is a plain Inertia `post` that preserves scroll: the page comes back with the new
 * `my_rsvp` on it, which is what makes the change visible immediately after.
 */

const props = defineProps<{ meeting: MeetingDetail }>();

const form = useForm<{ status: MeetingRsvp }>({ status: 'pending' });

/** The seats, with the organiser first — they called it, so they lead the list. */
const attendees = computed(() =>
    [...props.meeting.participants].sort((a, b) => {
        const organiser = props.meeting.organizer.id;

        if (a.id === organiser) {
            return -1;
        }

        if (b.id === organiser) {
            return 1;
        }

        return a.name.localeCompare(b.name);
    }),
);

function answer(status: MeetingRsvp): void {
    form.status = status;
    form.post(meetingRoutes(props.meeting.id).rsvp, { preserveScroll: true });
}
</script>

<template>
    <Card class="min-w-0 gap-4 p-6">
        <div class="flex min-w-0 items-center gap-2">
            <Users class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <h2 class="text-sm font-medium">
                In the room
                <span class="font-normal text-muted-foreground">({{ meeting.participants.length }})</span>
            </h2>
        </div>

        <!--
            This viewer's own answer. Drawn only when the server says they may give one — see
            the docblock for why there is no disabled variant of this block.
        -->
        <div v-if="meeting.permissions.can_rsvp" class="flex min-w-0 flex-col gap-2">
            <p id="meeting-rsvp-label" class="text-xs text-muted-foreground">Are you going?</p>
            <div class="flex min-w-0 flex-wrap gap-2" role="group" aria-labelledby="meeting-rsvp-label">
                <Button
                    v-for="choice in RSVP_CHOICES"
                    :key="choice"
                    type="button"
                    size="sm"
                    :variant="meeting.my_rsvp === choice ? 'secondary' : 'outline'"
                    :aria-pressed="meeting.my_rsvp === choice"
                    :disabled="form.processing"
                    @click="answer(choice)"
                >
                    <Check v-if="meeting.my_rsvp === choice" aria-hidden="true" />
                    {{ choice === 'pending' ? 'Undecided' : RSVP_LABEL[choice] }}
                </Button>
            </div>
        </div>

        <ul class="flex min-w-0 flex-col divide-y">
            <li
                v-for="attendee in attendees"
                :key="attendee.id"
                class="flex min-w-0 flex-col gap-1 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
            >
                <p class="min-w-0 truncate text-sm">
                    {{ attendee.name }}
                    <span v-if="attendee.id === meeting.organizer.id" class="text-xs text-muted-foreground">
                        · organiser
                    </span>
                </p>
                <StatusBadge
                    class="shrink-0 self-start sm:self-auto"
                    :status="RSVP_TONE[attendee.rsvp]"
                    :label="RSVP_LABEL[attendee.rsvp]"
                    size="sm"
                />
            </li>
        </ul>
    </Card>
</template>
