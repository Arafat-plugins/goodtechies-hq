<script setup lang="ts">
import { CheckCheck, ChevronDown, RotateCcw, Send, Undo2, XCircle } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { TaskDetail, TaskSurface, TaskTransition } from '@/Components/Tasks/taskDetail';
import {
    STATUS_CANCELLED,
    STATUS_CHANGES_REQUESTED,
    STATUS_COMPLETED,
    STATUS_IN_PROGRESS,
    STATUS_IN_REVIEW,
    focusField,
    formatDateTime,
    mutateTask,
    taskRoutes,
} from '@/Components/Tasks/taskDetail';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

/**
 * Every move this task can make, and the one thing each move has to say first.
 *
 * The list of moves is NOT computed here. `task.available_transitions` arrives already
 * filtered through `TaskPolicy::transition` — the same gate call the endpoint makes — so this
 * component's whole job is to label them, collect what the server will demand, and post to
 * the one status endpoint. A board drag in the next slice sends the same `{status, after_id}`
 * to the same `submit()` and inherits every dialog below by not being allowed to skip them.
 *
 * Three of the moves cannot be a bare button:
 *
 *  - **In review** needs a work summary. It is not a button that fails afterwards — the
 *    summary *is* the submission, so the textarea is the form and the button is its submit.
 *  - **Cancelled** and **reopening a completed task** need a reason. `TaskService::transition`
 *    throws `reasonRequired` without one, so the reason is asked for up front.
 *  - **Completed** needs the PRIMARY assignee's summary, which somebody else's work cannot
 *    satisfy. The dialog therefore shows the summary that is on the task and whose it is,
 *    before the click, rather than letting the server refuse it after.
 */

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
    /** Who may pass a verdict on this task, so the screen can name them when nobody here may. */
    reviewers: { id: number; name: string | null }[];
}>();

const emit = defineEmits<{
    /** A write landed. The page mount already has fresh props; the drawer re-reads. */
    settled: [];
    /** The viewer asked to hand the task over instead of completing it themselves. */
    'hand-off': [];
}>();

const routes = computed(() => taskRoutes(props.surface, props.task.id));

/* ----------------------------------------------------------------- labelling */

type Kind = 'plain' | 'review' | 'approve' | 'changes' | 'reopen' | 'cancel';

interface Move {
    transition: TaskTransition;
    kind: Kind;
    label: string;
    /** Verb moves get their own button; the rest live in the "Move to" menu. */
    prominent: boolean;
}

/** A move out of Completed back into progress is a reopening, not an ordinary step back. */
const isReopen = (to: string) => props.task.status === STATUS_COMPLETED && to === STATUS_IN_PROGRESS;

function classify(transition: TaskTransition): Move {
    const to = transition.value;

    if (to === STATUS_IN_REVIEW) {
        return { transition, kind: 'review', label: 'Submit for review', prominent: true };
    }

    if (to === STATUS_COMPLETED) {
        return { transition, kind: 'approve', label: 'Approve', prominent: true };
    }

    if (to === STATUS_CHANGES_REQUESTED) {
        return { transition, kind: 'changes', label: 'Request changes', prominent: true };
    }

    if (isReopen(to)) {
        return { transition, kind: 'reopen', label: 'Reopen', prominent: true };
    }

    /*
     * Cancelling is a move like any other as far as the machine is concerned, but it is not
     * one anybody reaches for — so it sits in the menu with the ordinary column moves, marked
     * destructive, rather than becoming the biggest button on a To-do task's page. Only the
     * review cycle's verbs get a button of their own.
     */
    if (to === STATUS_CANCELLED) {
        return { transition, kind: 'cancel', label: 'Cancel task', prominent: false };
    }

    return { transition, kind: 'plain', label: transition.label, prominent: false };
}

const moves = computed<Move[]>(() => props.task.available_transitions.map(classify));
const prominent = computed(() => moves.value.filter((move) => move.prominent));
const plain = computed(() => moves.value.filter((move) => !move.prominent));

const ICONS: Partial<Record<Kind, typeof Send>> = {
    review: Send,
    approve: CheckCheck,
    changes: Undo2,
    reopen: RotateCcw,
    cancel: XCircle,
};

/* -------------------------------------------------- the work-summary question */

/**
 * The summary on the task, and whose it is.
 *
 * `work_summary_by` is the USER who wrote it; `primary_assignee` is an EMPLOYEE — and it
 * carries `user_id`, the user behind that employee row. So the comparison the server makes in
 * `TaskService::assertCompletable()` is available here exactly:
 * `work_summary_by.id === primary_assignee.user_id`. Names are not used for it. Two people
 * called Rahim are not the same person, and a screen that says a task is completable because
 * two strings matched is guessing.
 *
 * It is still only what the screen SAYS before the click. The server decides, and checks again.
 */
const summary = computed(() => props.task.work_summary?.trim() ?? '');
const hasSummary = computed(() => summary.value.length > 0);
const summaryAuthor = computed(() => props.task.work_summary_by?.name ?? null);
const primaryName = computed(() => props.task.primary_assignee?.name ?? null);

const summaryIsPrimarys = computed(() => {
    const primary = props.task.primary_assignee;

    if (primary === null) {
        // Nobody is primary, so whoever completes it answers for the summary themselves —
        // `TaskService::assertCompletable()` returns early in exactly this case.
        return true;
    }

    const author = props.task.work_summary_by?.id ?? null;

    // A primary with no user row behind it can never own a summary, so the answer is no
    // rather than "unknown" — which is also what the server's comparison against a null
    // `user_id` comes to.
    return author !== null && primary.user_id !== null && author === primary.user_id;
});

/** Would Approve be refused as things stand? Shown before the click, never after. */
const completionBlocked = computed(() => !hasSummary.value || !summaryIsPrimarys.value);

const reviewerNames = computed(() =>
    props.reviewers.map((reviewer) => reviewer.name).filter((name): name is string => Boolean(name)),
);

/* --------------------------------------------------------------- the dialogs */

const open = ref<Kind | null>(null);
const pending = ref<Move | null>(null);
const text = ref('');
const submitting = ref(false);
const fieldError = ref<string | null>(null);
const field = ref<{ $el?: unknown } | null>(null);

function start(move: Move): void {
    if (move.kind === 'plain') {
        submit(move, {});

        return;
    }

    pending.value = move;
    fieldError.value = null;
    // Submitting for review starts from whatever summary is already on the task, so a
    // resubmission after "changes requested" is an edit rather than a retype.
    text.value = move.kind === 'review' ? summary.value : '';

    // Let a dropdown finish closing before the dialog takes the focus trap.
    setTimeout(() => {
        open.value = move.kind;
    }, 0);
}

/** The dialog's own field takes focus, so the first Tab inside is not a hunt. */
watch(open, (value) => {
    if (value === null || value === 'approve') {
        return;
    }

    void nextTick(() => focusField(field.value));
});

const REASON_REQUIRED: Kind[] = ['cancel', 'reopen'];

function confirm(): void {
    const move = pending.value;

    if (move === null || submitting.value) {
        return;
    }

    const value = text.value.trim();

    if (move.kind === 'review' && value === '') {
        fieldError.value = 'A work summary is what a submission is. Say what was done.';
        focusField(field.value);

        return;
    }

    if (REASON_REQUIRED.includes(move.kind) && value === '') {
        fieldError.value = 'This move has to say why.';
        focusField(field.value);

        return;
    }

    const payload: Record<string, unknown> = {};

    if (move.kind === 'review') {
        payload.work_summary = value;
    } else if (value !== '') {
        // A verdict note, a cancellation reason and a reopening reason are all `reason`:
        // the service writes it onto the activity line for the move.
        payload.reason = value;
    }

    submit(move, payload);
}

function submit(move: Move, payload: Record<string, unknown>): void {
    submitting.value = true;

    mutateTask(
        'post',
        routes.value.status,
        { status: move.transition.value, ...payload },
        {
            onAccepted: () => {
                open.value = null;
                pending.value = null;
                text.value = '';
            },
            onSettled: () => emit('settled'),
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

interface DialogCopy {
    title: string;
    description: string;
    /** The field's label, or the heading over the summary Approve reads. */
    label: string;
    /** The confirm button: a verb, and never the same string as the trigger that opened it. */
    confirm: string;
    busy: string;
}

const DIALOG_COPY: Record<Exclude<Kind, 'plain'>, DialogCopy> = {
    review: {
        title: 'Submit for review',
        description: 'A work summary goes with it — the reviewer reads this, and completion is checked against it.',
        label: 'Work summary',
        confirm: 'Send for review',
        busy: 'Sending…',
    },
    approve: {
        title: 'Approve this task?',
        description: 'It moves to Completed and this completion is recorded against the work summary below.',
        label: 'Work summary',
        confirm: 'Approve and complete',
        busy: 'Approving…',
    },
    changes: {
        title: 'Request changes',
        description: 'It goes back to the assignee. Say what needs changing — the note lands on the activity trail.',
        label: 'What needs changing',
        confirm: 'Send it back',
        busy: 'Sending…',
    },
    reopen: {
        title: 'Reopen this task?',
        description:
            'It moves back to In progress. The original completion is kept and stays visible; the reason goes on the activity trail.',
        label: 'Reason',
        confirm: 'Reopen the task',
        busy: 'Reopening…',
    },
    cancel: {
        title: 'Cancel this task?',
        description: 'Cancelling needs a reason — it goes on the task’s activity trail.',
        label: 'Reason',
        confirm: 'Cancel the task',
        busy: 'Cancelling…',
    },
};

const copy = computed(() => (pending.value ? DIALOG_COPY[pending.value.kind as Exclude<Kind, 'plain'>] : null));
const required = computed(
    () => pending.value !== null && (pending.value.kind === 'review' || REASON_REQUIRED.includes(pending.value.kind)),
);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-3">
        <div v-if="moves.length > 0" class="flex min-w-0 flex-wrap items-center gap-2">
            <Button
                v-for="move in prominent"
                :key="move.transition.value"
                type="button"
                :variant="move.kind === 'approve' ? 'default' : 'outline'"
                size="sm"
                :disabled="submitting"
                @click="start(move)"
            >
                <component :is="ICONS[move.kind]" v-if="ICONS[move.kind]" aria-hidden="true" />
                {{ move.label }}
            </Button>

            <DropdownMenu v-if="plain.length > 0">
                <DropdownMenuTrigger as-child>
                    <!--
                        Outline, always. `Approve` is the one filled button a task screen gets,
                        and only when it is offered: the shell already spends the screen's one
                        orange on the active nav rail (DESIGN.md §5.3).
                    -->
                    <Button type="button" variant="outline" size="sm" :disabled="submitting">
                        Change status
                        <ChevronDown aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    <DropdownMenuItem
                        v-for="move in plain"
                        :key="move.transition.value"
                        :variant="move.kind === 'cancel' ? 'destructive' : 'default'"
                        @select="start(move)"
                    >
                        <StatusBadge
                            v-if="move.kind !== 'cancel'"
                            :status="move.transition.tone"
                            :label="move.label"
                            size="sm"
                        />
                        <template v-else>
                            <XCircle aria-hidden="true" />
                            {{ move.label }}
                        </template>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <p v-else class="text-xs text-muted-foreground">
            <template v-if="task.is_archived">
                An archived task does not move. Unarchive it first.
            </template>
            <template v-else-if="task.status === 'in_review' && reviewerNames.length > 0">
                Waiting on a verdict from {{ reviewerNames.join(' or ') }}.
            </template>
            <template v-else>You have no moves on this task from {{ task.status_label }}.</template>
        </p>

        <!--
            The completion rule, said before the click. Amber-ish `waiting` tones would be a
            second accent; the Alert's own hairline plus the word carries it (DESIGN.md §5.6).
        -->
        <Alert v-if="completionBlocked && prominent.some((move) => move.kind === 'approve')">
            <AlertTitle class="text-sm font-medium">
                {{ hasSummary ? 'This summary is not the primary assignee’s' : 'No work summary yet' }}
            </AlertTitle>
            <AlertDescription class="text-xs text-muted-foreground">
                <template v-if="!hasSummary">
                    Completion needs a work summary, and it has to be
                    {{ primaryName ?? 'the primary assignee' }}’s. Send it back with
                    <em>Request changes</em> and ask for one.
                </template>
                <template v-else>
                    The summary on this task is {{ summaryAuthor ?? 'somebody else' }}’s.
                    Completion needs {{ primaryName }}’s — hand the task over to whoever is
                    finishing it, then approve.
                    <Button
                        type="button"
                        variant="link"
                        size="xs"
                        class="h-auto px-0"
                        @click="emit('hand-off')"
                    >
                        Hand it over…
                    </Button>
                </template>
            </AlertDescription>
        </Alert>
    </div>

    <Dialog
        :open="open !== null"
        @update:open="
            (value) => {
                if (!value) {
                    open = null;
                }
            }
        "
    >
        <DialogContent v-if="pending && copy" class="max-w-lg">
            <form novalidate @submit.prevent="confirm">
                <DialogHeader>
                    <DialogTitle>{{ copy.title }}</DialogTitle>
                    <DialogDescription>{{ copy.description }}</DialogDescription>
                </DialogHeader>

                <div class="flex min-w-0 flex-col gap-2 py-4">
                    <!--
                        Approve reads the summary rather than writing one: a summary typed
                        here would be the REVIEWER's, and `assertCompletable()` wants the
                        primary assignee's. Writing one would be the thing that breaks it.
                    -->
                    <template v-if="pending.kind === 'approve'">
                        <p class="text-xs font-medium text-muted-foreground">{{ copy.label }}</p>
                        <p v-if="hasSummary" class="rounded-md border bg-muted p-3 text-sm whitespace-pre-line">
                            {{ summary }}
                        </p>
                        <p v-else class="rounded-md border p-3 text-sm text-muted-foreground">
                            Nothing written yet.
                        </p>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="summaryAuthor">
                                By {{ summaryAuthor }} · {{ formatDateTime(task.work_summary_at) }}
                            </template>
                            <template v-else>Nobody has written one.</template>
                            <template v-if="primaryName">
                                · Completion is checked against {{ primaryName }}’s, as primary assignee.
                            </template>
                        </p>
                    </template>

                    <template v-else>
                        <Label for="task-status-reason">
                            {{ copy.label }}
                            <span v-if="required" class="text-destructive" aria-hidden="true">*</span>
                            <span v-if="required" class="sr-only">(required)</span>
                        </Label>
                        <Textarea
                            id="task-status-reason"
                            ref="field"
                            v-model="text"
                            rows="5"
                            :disabled="submitting"
                            :aria-invalid="fieldError ? true : undefined"
                            aria-describedby="task-status-reason-error"
                        />
                        <p id="task-status-reason-error" class="text-xs text-destructive">
                            {{ fieldError ?? '' }}
                        </p>
                    </template>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="submitting" @click="open = null">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :variant="pending.kind === 'cancel' ? 'destructive' : 'default'"
                        :disabled="submitting"
                    >
                        {{ submitting ? copy.busy : copy.confirm }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
