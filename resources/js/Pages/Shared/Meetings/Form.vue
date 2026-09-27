<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ExternalLink, Info } from '@lucide/vue';
import { computed, watch } from 'vue';
import MeetingParticipantsField from '@/Components/Meetings/MeetingParticipantsField.vue';
import type { Meeting, MeetingInvitee } from '@/Components/Meetings/meetings';
import { meetingRoutes, meetingsHref } from '@/Components/Meetings/meetings';
import PageShell from '@/Components/PageShell.vue';
import { Button, buttonVariants } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * Book a meeting, or fix one — **one component for both**, because it is the same seven fields
 * asking the same questions. The only differences are the verb, the endpoint and whether
 * `meeting` is null, and all three are one `computed` each.
 *
 * ## The Meet link, and where its two validations live
 *
 * Part D §12's MVP flow is manual: *"'Create Meet Link' opens Google's instant-meeting flow and
 * the organizer pastes the link back"*. So the button is an `<a target="_blank">` to whatever
 * `calendar.start_url` says — **the driver's answer, never a URL written into this file** — and
 * it exists only when `calendar.creates_itself` is false. On the API driver there is no button,
 * because the link arrives with the event.
 *
 * The shape of a pasted link is decided in **one place, in PHP**: `MeetLink::looksValid()`,
 * called by `StoreMeetingRequest` and again by `MeetingService`. There is deliberately **no
 * second regex in TypeScript** — a pattern written twice is a pattern corrected once. What this
 * file does instead is a courtesy that *cannot* drift, because it compares against a string the
 * server itself sent: if somebody pastes `calendar.start_url` back into the field, they have
 * pasted the button rather than the room, and we say so before the round trip. Everything else
 * goes to the server and comes back as a field error.
 *
 * ## End after start
 *
 * The server refuses it (`after:start_at`, and the `meetings_end_after_start` constraint behind
 * that). The form says so while you type, and moving the start drags an end that would otherwise
 * be left behind — so the ordinary way of using the form cannot produce the error at all. Submit
 * is still blocked on it here, and the server is still the one that decides: bypassing this
 * check gets a 422, not a saved meeting.
 */

defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

/** The "nothing linked" option. A `Select` cannot hold an empty value, so it holds a word. */
const NOTHING = 'none';

interface NamedOption {
    id: number;
    name: string;
}

const props = defineProps<{
    /** Null on create. On edit it is the meeting as `MeetingResource` sends it. */
    meeting: Meeting | null;
    initial: {
        title: string;
        start_at: string;
        end_at: string;
        project_id: number | null;
        task_id: number | null;
        agenda: string;
        meet_link: string;
        participants: number[];
    };
    organizer: MeetingInvitee;
    people: MeetingInvitee[];
    projects: NamedOption[];
    tasks: NamedOption[];
    calendar: { driver: string; creates_itself: boolean; start_url: string | null };
}>();

const isEdit = computed(() => props.meeting !== null);

const form = useForm({
    title: props.initial.title,
    start_at: props.initial.start_at,
    end_at: props.initial.end_at,
    project_id: props.initial.project_id === null ? NOTHING : String(props.initial.project_id),
    task_id: props.initial.task_id === null ? NOTHING : String(props.initial.task_id),
    agenda: props.initial.agenda,
    meet_link: props.initial.meet_link,
    participants: [...props.initial.participants],
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

/* ------------------------------------------------------------------ the two times */

const timesOutOfOrder = computed(
    () => form.start_at !== '' && form.end_at !== '' && form.end_at <= form.start_at,
);

/**
 * Moving the start drags the end with it, keeping whatever gap was there (or half an hour).
 *
 * String comparison is enough to *detect* the problem — `YYYY-MM-DDTHH:mm` sorts correctly — but
 * fixing it needs real arithmetic, so this is the one place the form parses a date.
 */
watch(
    () => form.start_at,
    (next, previous) => {
        if (next === '' || form.end_at === '') {
            return;
        }

        const start = new Date(next);
        const end = new Date(form.end_at);

        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
            return;
        }

        const before = previous === undefined || previous === '' ? null : new Date(previous);
        const gap =
            before !== null && !Number.isNaN(before.getTime()) && end > before
                ? end.getTime() - before.getTime()
                : 30 * 60 * 1000;

        if (end <= start) {
            form.end_at = toInputValue(new Date(start.getTime() + gap));
        }
    },
);

function toInputValue(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return (
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
        `T${pad(date.getHours())}:${pad(date.getMinutes())}`
    );
}

/* ------------------------------------------------------------------ the Meet link */

const showMeetButton = computed(() => !props.calendar.creates_itself && props.calendar.start_url !== null);

/**
 * The one client-side complaint about a pasted link, and it compares against a value the server
 * sent rather than a pattern this file invented — so it cannot disagree with PHP.
 */
const pastedTheButton = computed(
    () =>
        props.calendar.start_url !== null &&
        form.meet_link.trim() !== '' &&
        form.meet_link.trim() === props.calendar.start_url,
);

/* ------------------------------------------------------------------ submitting */

function submit(): void {
    if (timesOutOfOrder.value) {
        form.setError('end_at', 'The meeting has to end after it starts.');
        (document.getElementById('meeting-end-at') as HTMLInputElement | null)?.focus();

        return;
    }

    form.transform((data) => ({
        ...data,
        project_id: data.project_id === NOTHING ? null : Number(data.project_id),
        task_id: data.task_id === NOTHING ? null : Number(data.task_id),
    }));

    if (props.meeting !== null) {
        form.put(meetingRoutes(props.meeting.id).update, { preserveScroll: true });

        return;
    }

    form.post('/meetings', { preserveScroll: true });
}

const heading = computed(() => (isEdit.value ? 'Edit meeting' : 'New meeting'));
</script>

<template>
    <Head :title="heading" />

    <PageShell
        :title="heading"
        :description="
            isEdit
                ? 'Change the time, the room or the agenda. Everyone invited is told when the time moves.'
                : 'Pick a time, invite the room, and paste a Meet link if you have one.'
        "
    >
        <form class="flex min-w-0 max-w-3xl flex-col gap-6" novalidate @submit.prevent="submit">
            <Card class="min-w-0 gap-4">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">What and when</CardTitle>
                </CardHeader>
                <CardContent class="grid min-w-0 gap-4 md:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-2 md:col-span-2">
                        <Label for="meeting-title">
                            Title <span class="text-destructive" aria-hidden="true">*</span>
                            <span class="sr-only">(required)</span>
                        </Label>
                        <Input
                            id="meeting-title"
                            v-model="form.title"
                            name="title"
                            maxlength="200"
                            :disabled="form.processing"
                            :aria-invalid="errors.title ? true : undefined"
                            :aria-describedby="errors.title ? 'meeting-title-error' : undefined"
                        />
                        <p v-if="errors.title" id="meeting-title-error" class="text-xs text-destructive">
                            {{ errors.title }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="meeting-start-at">
                            Starts <span class="text-destructive" aria-hidden="true">*</span>
                            <span class="sr-only">(required)</span>
                        </Label>
                        <Input
                            id="meeting-start-at"
                            v-model="form.start_at"
                            type="datetime-local"
                            name="start_at"
                            :disabled="form.processing"
                            :aria-invalid="errors.start_at ? true : undefined"
                        />
                        <p v-if="errors.start_at" class="text-xs text-destructive">{{ errors.start_at }}</p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="meeting-end-at">
                            Ends <span class="text-destructive" aria-hidden="true">*</span>
                            <span class="sr-only">(required)</span>
                        </Label>
                        <Input
                            id="meeting-end-at"
                            v-model="form.end_at"
                            type="datetime-local"
                            name="end_at"
                            :disabled="form.processing"
                            :aria-invalid="errors.end_at || timesOutOfOrder ? true : undefined"
                            aria-describedby="meeting-end-at-help"
                        />
                        <p
                            v-if="timesOutOfOrder || errors.end_at"
                            id="meeting-end-at-help"
                            class="text-xs text-destructive"
                            role="alert"
                        >
                            {{ errors.end_at ?? 'The meeting has to end after it starts.' }}
                        </p>
                        <p v-else id="meeting-end-at-help" class="sr-only">
                            The meeting has to end after it starts.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card class="min-w-0 gap-4">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">The room</CardTitle>
                    <CardDescription>
                        You are always in it. Everyone you add is notified and can answer.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <MeetingParticipantsField
                        v-model="form.participants"
                        :people="people"
                        :organizer="organizer"
                        :disabled="form.processing"
                        :error="errors.participants"
                    />
                </CardContent>
            </Card>

            <Card class="min-w-0 gap-4">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">What it is about</CardTitle>
                    <CardDescription>
                        Optional. Only projects and tasks you can already see are listed.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid min-w-0 gap-4 md:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="meeting-project">Linked project</Label>
                        <Select v-model="form.project_id" :disabled="form.processing">
                            <SelectTrigger
                                id="meeting-project"
                                class="w-full"
                                :aria-invalid="errors.project_id ? true : undefined"
                            >
                                <SelectValue placeholder="No project" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NOTHING">No project</SelectItem>
                                <SelectItem
                                    v-for="project in projects"
                                    :key="project.id"
                                    :value="String(project.id)"
                                >
                                    {{ project.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="errors.project_id" class="text-xs text-destructive">{{ errors.project_id }}</p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="meeting-task">Linked task</Label>
                        <Select v-model="form.task_id" :disabled="form.processing">
                            <SelectTrigger
                                id="meeting-task"
                                class="w-full"
                                :aria-invalid="errors.task_id ? true : undefined"
                            >
                                <SelectValue placeholder="No task" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NOTHING">No task</SelectItem>
                                <SelectItem v-for="task in tasks" :key="task.id" :value="String(task.id)">
                                    {{ task.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="errors.task_id" class="text-xs text-destructive">{{ errors.task_id }}</p>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2 md:col-span-2">
                        <Label for="meeting-agenda">Agenda</Label>
                        <Textarea
                            id="meeting-agenda"
                            v-model="form.agenda"
                            name="agenda"
                            rows="4"
                            maxlength="5000"
                            :disabled="form.processing"
                            :aria-invalid="errors.agenda ? true : undefined"
                        />
                        <p v-if="errors.agenda" class="text-xs text-destructive">{{ errors.agenda }}</p>
                    </div>
                </CardContent>
            </Card>

            <Card class="min-w-0 gap-4">
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Google Meet</CardTitle>
                    <CardDescription v-if="showMeetButton">
                        This workspace creates Meet links by hand. Open Google’s instant-meeting page, start the
                        meeting, copy the link it shows you, and paste it below.
                    </CardDescription>
                    <CardDescription v-else>
                        The calendar creates the link with the event, so there is nothing to paste.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex min-w-0 flex-col gap-3">
                    <a
                        v-if="showMeetButton"
                        :href="calendar.start_url ?? undefined"
                        target="_blank"
                        rel="noopener noreferrer"
                        :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'w-fit')"
                    >
                        <ExternalLink aria-hidden="true" />
                        Create Meet Link
                        <span class="sr-only">(opens Google in a new tab)</span>
                    </a>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="meeting-meet-link">Meet link</Label>
                        <Input
                            id="meeting-meet-link"
                            v-model="form.meet_link"
                            name="meet_link"
                            inputmode="url"
                            placeholder="https://meet.google.com/abc-defg-hij"
                            :disabled="form.processing"
                            :aria-invalid="errors.meet_link || pastedTheButton ? true : undefined"
                            aria-describedby="meeting-meet-link-help"
                        />
                        <p
                            v-if="pastedTheButton"
                            id="meeting-meet-link-help"
                            class="text-xs text-destructive"
                            role="alert"
                        >
                            That is the address the button above opens, not a link to a room. Open it, start the
                            meeting, then copy the link Google shows you and paste that here.
                        </p>
                        <p
                            v-else-if="errors.meet_link"
                            id="meeting-meet-link-help"
                            class="text-xs text-destructive"
                        >
                            {{ errors.meet_link }}
                        </p>
                        <p
                            v-else
                            id="meeting-meet-link-help"
                            class="flex min-w-0 items-start gap-1.5 text-xs text-muted-foreground"
                        >
                            <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                            <span>Optional. Leave it empty if the meeting is in person.</span>
                        </p>
                    </div>
                </CardContent>
            </Card>

            <div class="flex min-w-0 flex-wrap items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ isEdit ? 'Save changes' : 'Schedule meeting' }}
                </Button>
                <Link
                    :href="meeting ? meetingRoutes(meeting.id).show : meetingsHref()"
                    :class="cn(buttonVariants({ variant: 'ghost' }))"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </PageShell>
</template>
