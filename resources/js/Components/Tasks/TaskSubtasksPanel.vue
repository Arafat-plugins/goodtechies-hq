<script setup lang="ts">
import { ArrowRight, CalendarDays, Circle, CircleCheck, Plus, UserRound, X } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { personTone } from '@/Components/Messages/people';
import DueCountdown from '@/Components/Tasks/DueCountdown.vue';
import type { TaskNamedRef } from '@/Components/Tasks/TaskList.vue';
import type { TaskDetail, TaskSubtask, TaskSurface } from '@/Components/Tasks/taskDetail';
import {
    STATUS_COMPLETED,
    STATUS_IN_REVIEW,
    focusField,
    formatDate,
    initials,
    mutateTask,
    taskRoutes,
} from '@/Components/Tasks/taskDetail';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Textarea } from '@/Components/ui/textarea';
import { TooltipProvider } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * Subtasks (flow F2, decision 12-71): real tasks under this one, each with its own person, due
 * date and status — unlike the checklist beside it, whose lines are ticks with no owner.
 *
 * Mirrors `TaskChecklistPanel`: rows, then an inline add form; every write through
 * `mutateTask`, no optimistic state, `settled` after each.
 *
 * **The check is one step forward, not "done".** The status machine has no shortcut to
 * Completed — work goes to In review with a work summary and a reviewer passes it — so the
 * check makes the next forward move the SERVER offers as `check_to` (To do → In progress →
 * In review → Completed) and is disabled when there is none for this reader. The move into In
 * review asks for the work summary inline, because the machine refuses it without one. The
 * status endpoint is the ordinary one; nothing here decides what is allowed.
 *
 * Whether the add row shows is `task.can_add_subtask` — `TaskPolicy::createSubtask` — so a
 * subtask (one level deep) and an employee both get the list and no form.
 */

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
    /** Admin detail only: the assignee picker's options. */
    employees?: TaskNamedRef[];
}>();

const emit = defineEmits<{
    settled: [];
    /** A subtask's title was clicked: show it (the drawer swaps in place, the page visits). */
    open: [id: number];
}>();

const subtasks = computed<TaskSubtask[]>(() => props.task.subtasks ?? []);
const done = computed(() => subtasks.value.filter((row) => row.status === STATUS_COMPLETED).length);

/* ------------------------------------------------------------------ the check */

const busy = ref<number | null>(null);
/** The row whose move into In review is waiting for a work summary. */
const summaryFor = ref<number | null>(null);
const summary = ref('');

function check(row: TaskSubtask): void {
    if (!row.check_to || busy.value !== null) {
        return;
    }

    if (row.check_to.value === STATUS_IN_REVIEW) {
        summaryFor.value = row.id;
        summary.value = '';

        return;
    }

    move(row, {});
}

function submitSummary(row: TaskSubtask): void {
    const text = summary.value.trim();

    if (text === '') {
        return;
    }

    move(row, { work_summary: text });
}

function move(row: TaskSubtask, extra: Record<string, string>): void {
    if (!row.check_to) {
        return;
    }

    busy.value = row.id;

    mutateTask(
        'post',
        taskRoutes(props.surface, row.id).status,
        { status: row.check_to.value, ...extra },
        {
            onAccepted: () => {
                summaryFor.value = null;
            },
            onSettled: () => emit('settled'),
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

/* ------------------------------------------------------------------ adding */

const title = ref('');
const assignee = ref<string>('none');
const due = ref('');
const adding = ref(false);

/** Brief 014: the add row's two icon buttons open these; the choice shows as a chip / avatar. */
const dueOpen = ref(false);
const whoOpen = ref(false);
const titleField = ref<{ $el?: unknown } | null>(null);
const chosenPerson = computed(() =>
    assignee.value === 'none' ? null : (props.employees ?? []).find((person) => String(person.id) === assignee.value) ?? null,
);

function pick(id: string): void {
    assignee.value = id;
    whoOpen.value = false;
    focusField(titleField.value);
}

function add(): void {
    const value = title.value.trim();

    if (value === '' || adding.value) {
        return;
    }

    adding.value = true;

    mutateTask(
        'post',
        `/${props.surface}/tasks/${props.task.id}/subtasks`,
        {
            title: value,
            assignee_id: assignee.value === 'none' ? null : Number(assignee.value),
            due_date: due.value === '' ? null : due.value,
        },
        {
            onAccepted: () => {
                title.value = '';
                due.value = '';
                assignee.value = 'none';
            },
            onSettled: () => emit('settled'),
            onFinish: () => {
                adding.value = false;
                void nextTick(() => focusField(titleField.value));
            },
        },
    );
}
</script>

<template>
    <!--
        Brief 014: Asana's Subtasks — a heading with a count and a `+`, compact rows (check ·
        title · date chip · avatar), and one "Add subtask" line whose calendar and person icon
        buttons sit on the right. Enter adds. Same `POST …/subtasks` as before.
    -->
    <section class="flex min-w-0 flex-col gap-1" data-subtasks-panel>
        <div class="flex min-w-0 items-center gap-2">
            <h3 class="text-sm font-semibold">Subtasks</h3>
            <span v-if="subtasks.length > 0" class="text-xs text-muted-foreground tabular-nums">
                {{ done }}/{{ subtasks.length }}
            </span>
            <Button
                v-if="task.can_add_subtask"
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label="Add subtask"
                @click="focusField(titleField)"
            >
                <Plus aria-hidden="true" />
            </Button>
        </div>

        <!-- `DueCountdown` is a Tooltip, which needs a provider above it, as on the card. -->
        <TooltipProvider v-if="subtasks.length > 0">
            <ul class="flex min-w-0 flex-col divide-y border-t">
                <li v-for="row in subtasks" :key="row.id" class="group flex min-w-0 flex-col gap-2 py-0.5">
                    <div class="flex min-w-0 items-center gap-2">
                        <!--
                            Not colour alone: a completed row is a filled check and a struck
                            title; the button's name says which move it makes.
                        -->
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="shrink-0"
                            :disabled="!row.check_to || busy === row.id"
                            :aria-label="
                                row.check_to
                                    ? `Move ${row.title} to ${row.check_to.label}`
                                    : `${row.title}: ${row.status_label}`
                            "
                            :title="row.check_to ? `Move to ${row.check_to.label}` : row.status_label"
                            data-subtask-check
                            @click="check(row)"
                        >
                            <CircleCheck v-if="row.status === STATUS_COMPLETED" class="text-primary" aria-hidden="true" />
                            <Circle v-else aria-hidden="true" />
                        </Button>

                        <!-- A subtask is a full task: the title opens it, and says so on hover. -->
                        <button
                            type="button"
                            data-subtask-title
                            :title="`Open ${row.title}`"
                            class="flex min-w-0 flex-1 items-center gap-2 rounded-sm py-1 text-left text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring"
                            @click="emit('open', row.id)"
                        >
                            <span
                                :class="
                                    cn(
                                        'min-w-0 truncate group-hover:underline',
                                        row.status === STATUS_COMPLETED && 'text-muted-foreground line-through',
                                    )
                                "
                            >
                                {{ row.title }}
                            </span>
                            <span class="sr-only">({{ row.status_label }})</span>
                            <span
                                class="hidden shrink-0 items-center gap-0.5 text-xs text-muted-foreground group-focus-within:inline-flex group-hover:inline-flex"
                                aria-hidden="true"
                            >
                                Open
                                <ArrowRight class="size-3" />
                            </span>
                        </button>

                        <DueCountdown :due-date="row.due_date" :status="row.status" />

                        <Avatar
                            v-for="person in row.assignees.slice(0, 1)"
                            :key="person.id"
                            role="img"
                            :aria-label="person.name ?? 'Unnamed assignee'"
                            :title="person.name ?? 'Unnamed assignee'"
                            class="size-6 shrink-0"
                        >
                            <AvatarFallback :class="cn('text-xs', personTone(person.id).avatar)" aria-hidden="true">
                                {{ initials(person.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span
                            v-if="row.assignees.length === 0"
                            role="img"
                            aria-label="Unassigned"
                            title="Unassigned"
                            class="inline-flex size-6 shrink-0 rounded-full border border-dashed border-muted-foreground"
                        />
                    </div>

                    <!-- The machine's one requirement for In review: a work summary. -->
                    <form
                        v-if="summaryFor === row.id"
                        class="flex min-w-0 flex-col gap-2 pb-2 pl-9"
                        novalidate
                        @submit.prevent="submitSummary(row)"
                    >
                        <Label :for="`subtask-summary-${row.id}`" class="text-xs text-muted-foreground">
                            Work summary — required to send it for review
                        </Label>
                        <Textarea :id="`subtask-summary-${row.id}`" v-model="summary" rows="2" :disabled="busy === row.id" />
                        <div class="flex flex-wrap gap-2">
                            <Button type="submit" size="sm" :disabled="busy === row.id || summary.trim() === ''">
                                Send for review
                            </Button>
                            <Button type="button" size="sm" variant="outline" :disabled="busy === row.id" @click="summaryFor = null">
                                Cancel
                            </Button>
                        </div>
                    </form>
                </li>
            </ul>
        </TooltipProvider>

        <!-- The one add line. Enter adds; the icon buttons set the date and the person first. -->
        <div
            v-if="task.can_add_subtask"
            :class="cn('flex min-w-0 items-center gap-1 border-y py-1', subtasks.length > 0 && 'border-t-0')"
            data-subtask-add
        >
            <Label :for="`subtask-new-${task.id}`" class="sr-only">Add subtask</Label>
            <Input
                :id="`subtask-new-${task.id}`"
                ref="titleField"
                v-model="title"
                :disabled="adding"
                placeholder="Add subtask"
                class="h-8 min-w-0 flex-1 border-transparent px-2 shadow-flat focus-visible:border-transparent! dark:bg-transparent"
                @keydown.enter.prevent="add"
            />

            <span
                v-if="due !== ''"
                class="inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs tabular-nums"
                data-subtask-due-chip
            >
                {{ formatDate(due) }}
                <button type="button" class="rounded-full outline-none focus-visible:ring-3 focus-visible:ring-ring" aria-label="Clear the due date" @click="due = ''">
                    <X class="size-3" aria-hidden="true" />
                </button>
            </span>
            <Popover v-model:open="dueOpen">
                <PopoverTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0 rounded-full text-muted-foreground"
                        aria-label="Due date"
                        title="Due date"
                        data-subtask-due-button
                    >
                        <CalendarDays aria-hidden="true" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="end" class="flex w-auto flex-col gap-2 p-3">
                    <Label :for="`subtask-due-${task.id}`" class="text-xs">Due date</Label>
                    <Input
                        :id="`subtask-due-${task.id}`"
                        v-model="due"
                        type="date"
                        :disabled="adding"
                        @keydown.enter.prevent="dueOpen = false"
                    />
                </PopoverContent>
            </Popover>

            <Popover v-if="(employees?.length ?? 0) > 0" v-model:open="whoOpen">
                <PopoverTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0 rounded-full text-muted-foreground"
                        :aria-label="chosenPerson ? `Assignee: ${chosenPerson.name}` : 'Assignee'"
                        :title="chosenPerson?.name ?? 'Assignee'"
                        data-subtask-person-button
                    >
                        <Avatar v-if="chosenPerson" class="size-6">
                            <AvatarFallback :class="cn('text-xs', personTone(chosenPerson.id).avatar)" aria-hidden="true">
                                {{ initials(chosenPerson.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <UserRound v-else aria-hidden="true" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="end" class="flex max-h-72 w-56 flex-col gap-0.5 overflow-y-auto p-1">
                    <p class="px-2 py-1 text-xs font-medium text-muted-foreground">Assignee</p>
                    <button
                        type="button"
                        class="rounded-sm px-2 py-1.5 text-left text-sm outline-none hover:bg-accent focus-visible:bg-accent"
                        @click="pick('none')"
                    >
                        Unassigned
                    </button>
                    <button
                        v-for="person in employees ?? []"
                        :key="person.id"
                        type="button"
                        :class="
                            cn(
                                'flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm outline-none hover:bg-accent focus-visible:bg-accent',
                                assignee === String(person.id) && 'font-medium',
                            )
                        "
                        data-subtask-person-option
                        @click="pick(String(person.id))"
                    >
                        <Avatar class="size-5">
                            <AvatarFallback :class="cn('text-xs', personTone(person.id).avatar)" aria-hidden="true">
                                {{ initials(person.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span class="min-w-0 truncate">{{ person.name }}</span>
                    </button>
                </PopoverContent>
            </Popover>
        </div>
    </section>
</template>
