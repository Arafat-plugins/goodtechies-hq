<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { FormDataConvertible } from '@inertiajs/core';
import { FolderInput, Pencil, Plus, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import { tagTone } from '@/Components/Tasks/TaskList.vue';
import type { TaskDetail, TaskSurface } from '@/Components/Tasks/taskDetail';
import { focusField, formatDate, formatMinutes, mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

/**
 * The task's own fields: what it is, when it is due, how long it should take, what it is
 * labelled with, and — on the Admin surface — which project it belongs to.
 *
 * The split between the plan and the work is the server's, in `UpdateTaskRequest`: an
 * employee sending `title`, `priority`, `start_date`, `due_date` or `project_id` gets a
 * `prohibited` validation error with a sentence, not a silently dropped field. This screen
 * shows those fields to everybody and offers them for editing only where the request would
 * accept them, so nobody types into a control that is going to be refused.
 *
 * **Tags are assigned here, on both surfaces.** `PUT` takes `tag_ids` from an employee too —
 * the plan's rule is that they assign existing labels and never create one — so the options
 * list is what the controller sends: this project's tags plus the global ones, which is
 * exactly the set the request accepts. Creating, renaming and deleting a tag is not reachable
 * from this screen and is not part of this slice.
 *
 * **Moving the task between projects is Admin-only**, because `project_id` is `prohibited` for
 * anybody who may not change the plan: the employee page is sent no `projects` list and draws
 * no control, rather than drawing one that exists to be refused (decisions 0.5-16, 0.5-19).
 * The move detaches every tag scoped to the old project — globals travel — so the dialog says
 * so, by name, before the move rather than after it.
 */

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
    /** Admin surface only; the employee page is sent none, and may not set one anyway. */
    priorities?: TaskOption[];
    /** The tag picker's options: this project's tags plus the global ones. Both surfaces. */
    tags?: TaskTag[];
    /** The move picker's options. Admin surface only — `project_id` is prohibited elsewhere. */
    projects?: TaskNamedRef[];
}>();

const emit = defineEmits<{ settled: [] }>();

const page = usePage();
const routes = computed(() => taskRoutes(props.surface, props.task.id));

/**
 * A UI hint, never the enforcement: the same split is a `prohibited` rule in the Form
 * Request, which is what actually refuses an employee's date change.
 */
const mayPlan = computed(() => {
    const role = page.props.auth.user?.role;

    return role === 'ADMIN' || role === 'MANAGER';
});

const editable = computed(() => props.task.permissions.can_update);

const editing = ref(false);
const saving = ref(false);
const opener = ref<{ $el?: unknown } | null>(null);
const firstField = ref<{ $el?: unknown } | null>(null);

const draft = ref({
    title: '',
    description: '',
    priority: '',
    start_date: '',
    due_date: '',
    estimated_minutes: '',
});

function edit(): void {
    draft.value = {
        title: props.task.title,
        description: props.task.description ?? '',
        priority: props.task.priority ?? '',
        start_date: props.task.start_date ?? '',
        due_date: props.task.due_date ?? '',
        estimated_minutes: props.task.estimated_minutes === null ? '' : String(props.task.estimated_minutes),
    };
    editing.value = true;
    void nextTick(() => focusField(firstField.value));
}

function cancel(): void {
    editing.value = false;
    void nextTick(() => focusField(opener.value));
}

const blank = (value: string) => (value.trim() === '' ? null : value.trim());

function save(): void {
    if (saving.value) {
        return;
    }

    saving.value = true;

    // Only what this requester may write is sent. A plan field from an employee would come
    // back as a `prohibited` error, which is correct but is not a thing to make them see.
    const payload: Record<string, FormDataConvertible> = {
        description: blank(draft.value.description),
        estimated_minutes:
            draft.value.estimated_minutes.trim() === '' ? null : Number(draft.value.estimated_minutes),
    };

    if (mayPlan.value) {
        payload.title = draft.value.title.trim();
        payload.priority = blank(draft.value.priority);
        payload.start_date = blank(draft.value.start_date);
        payload.due_date = blank(draft.value.due_date);
    }

    mutateTask('put', routes.value.update, payload, {
        onAccepted: () => {
            editing.value = false;
            void nextTick(() => focusField(opener.value));
        },
        onSettled: () => emit('settled'),
        onFinish: () => {
            saving.value = false;
        },
    });
}

const formErrors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

/* ----------------------------------------------------------------------- tags */

/** `Task::MAX_TAGS`. The request refuses an eleventh; the picker says so before it does. */
const MAX_TAGS = 10;

/**
 * Assigning is an edit, so it needs exactly what an edit needs — `tasks.update`, which
 * `permissions.can_update` mirrors and which is already false on an archived task. Without an
 * options list (an older payload, or a project with nothing usable) there is nothing to pick.
 */
const mayTag = computed(() => editable.value && (props.tags?.length ?? 0) > 0);

const attachedIds = computed(() => props.task.tags.map((tag) => tag.id));

/** What is not already on the task, split the way the server splits it. */
const untagged = computed(() => (props.tags ?? []).filter((tag) => !attachedIds.value.includes(tag.id)));
const globalChoices = computed(() => untagged.value.filter((tag) => tag.is_global));
const scopedChoices = computed(() => untagged.value.filter((tag) => !tag.is_global));

/** The task's own tags that belong to this project alone — the ones a move leaves behind. */
const scopedOnTask = computed(() => props.task.tags.filter((tag) => !tag.is_global));

const full = computed(() => props.task.tags.length >= MAX_TAGS);

/**
 * `tag_ids` errors arrive against the array (`tag_ids`) or against one element
 * (`tag_ids.0`, …) — a refused tag is the second kind, and printing only the first key would
 * silently swallow it.
 */
const tagError = computed(() => {
    const key = Object.keys(formErrors.value).find((name) => name === 'tag_ids' || name.startsWith('tag_ids.'));

    return key === undefined ? null : formErrors.value[key] ?? null;
});

const chosenTag = ref('');
const tagging = ref(false);
const busyTag = ref<number | null>(null);
const tagTrigger = ref<{ $el?: unknown } | null>(null);

/**
 * Tags are written as the whole list, because `tag_ids` is a sync: the request replaces the
 * set rather than adding to it, which is what makes "clear them" expressible at all.
 */
function writeTags(ids: number[], done: () => void): void {
    mutateTask('put', routes.value.update, { tag_ids: ids }, {
        onAccepted: () => {
            chosenTag.value = '';
        },
        onSettled: () => emit('settled'),
        onFinish: done,
    });
}

function addTag(): void {
    const id = Number(chosenTag.value);

    if (chosenTag.value === '' || tagging.value || Number.isNaN(id)) {
        return;
    }

    tagging.value = true;
    writeTags([...attachedIds.value, id], () => {
        tagging.value = false;
    });
}

/**
 * Removing a tag deletes the button that was clicked, and a keyboard user whose focused
 * element vanishes is dropped on `<body>` — back to the top of the page on the next Tab.
 * Focus therefore moves to the picker beside it, which is where the next thing they might
 * do is anyway.
 */
function removeTag(id: number): void {
    if (busyTag.value !== null) {
        return;
    }

    busyTag.value = id;
    writeTags(
        attachedIds.value.filter((value) => value !== id),
        () => {
            busyTag.value = null;
            void nextTick(() => focusField(tagTrigger.value));
        },
    );
}

/* ------------------------------------------------------- moving to another project */

/**
 * Admin surface only, and only for somebody who may change the plan: `project_id` is
 * `prohibited` for everybody else, so a control here would be a control that exists to be
 * told no. There is nowhere to move a task that is the only project, either.
 */
const otherProjects = computed(() => (props.projects ?? []).filter((project) => project.id !== props.task.project?.id));

const mayMove = computed(
    () => props.surface === 'admin' && mayPlan.value && editable.value && otherProjects.value.length > 0,
);

const moveOpen = ref(false);
const moveTo = ref('');
const moving = ref(false);
const moveError = ref<string | null>(null);
const moveOpener = ref<{ $el?: unknown } | null>(null);

function openMove(): void {
    moveTo.value = '';
    moveError.value = null;
    moveOpen.value = true;
}

/** The dialog closes back onto the control that opened it, however it was closed. */
watch(moveOpen, (open) => {
    if (!open) {
        void nextTick(() => focusField(moveOpener.value));
    }
});

function move(): void {
    if (moving.value) {
        return;
    }

    if (moveTo.value === '') {
        moveError.value = 'Pick the project it is moving to.';

        return;
    }

    moving.value = true;

    // `project_id` alone: saying nothing about `tag_ids` is what lets the service drop the
    // tags the new project cannot use, in the same transaction as the move.
    mutateTask('put', routes.value.update, { project_id: Number(moveTo.value) }, {
        onAccepted: () => {
            moveOpen.value = false;
        },
        onSettled: () => emit('settled'),
        onFinish: () => {
            moving.value = false;
        },
    });
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Details</CardTitle>
            <CardDescription v-if="!mayPlan">
                Dates, priority and the title are set by an administrator or a manager.
            </CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-4">
            <form v-if="editing" class="flex min-w-0 flex-col gap-4" novalidate @submit.prevent="save">
                <div v-if="mayPlan" class="flex min-w-0 flex-col gap-2">
                    <Label for="task-title">Name</Label>
                    <Input id="task-title" ref="firstField" v-model="draft.title" :disabled="saving" />
                    <p v-if="formErrors.title" class="text-xs text-destructive">{{ formErrors.title }}</p>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <Label for="task-description">Description</Label>
                    <Textarea id="task-description" v-model="draft.description" rows="5" :disabled="saving" />
                    <p v-if="formErrors.description" class="text-xs text-destructive">
                        {{ formErrors.description }}
                    </p>
                </div>

                <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                    <div v-if="mayPlan && priorities?.length" class="flex min-w-0 flex-col gap-2">
                        <Label for="task-priority">Priority</Label>
                        <Select v-model="draft.priority">
                            <SelectTrigger id="task-priority" class="w-full">
                                <SelectValue placeholder="Pick one…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="priority in priorities"
                                    :key="priority.value"
                                    :value="priority.value"
                                >
                                    {{ priority.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="task-estimate">Estimate (minutes)</Label>
                        <Input
                            id="task-estimate"
                            v-model="draft.estimated_minutes"
                            type="number"
                            min="0"
                            :disabled="saving"
                        />
                        <p v-if="formErrors.estimated_minutes" class="text-xs text-destructive">
                            {{ formErrors.estimated_minutes }}
                        </p>
                    </div>

                    <div v-if="mayPlan" class="flex min-w-0 flex-col gap-2">
                        <Label for="task-start-date">Start date</Label>
                        <Input id="task-start-date" v-model="draft.start_date" type="date" :disabled="saving" />
                        <p v-if="formErrors.start_date" class="text-xs text-destructive">
                            {{ formErrors.start_date }}
                        </p>
                    </div>

                    <div v-if="mayPlan" class="flex min-w-0 flex-col gap-2">
                        <Label for="task-due-date">Due date</Label>
                        <Input id="task-due-date" v-model="draft.due_date" type="date" :disabled="saving" />
                        <p v-if="formErrors.due_date" class="text-xs text-destructive">
                            {{ formErrors.due_date }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" size="sm" :disabled="saving">
                        {{ saving ? 'Saving…' : 'Save details' }}
                    </Button>
                    <Button type="button" size="sm" variant="outline" :disabled="saving" @click="cancel">
                        Cancel
                    </Button>
                </div>
            </form>

            <template v-else>
                <div class="flex min-w-0 flex-col gap-1">
                    <p class="text-xs font-medium text-muted-foreground">Description</p>
                    <p v-if="task.description" class="min-w-0 text-sm whitespace-pre-line">
                        {{ task.description }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">No description.</p>
                </div>

                <dl class="grid min-w-0 gap-4 sm:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-1">
                        <dt class="text-xs font-medium text-muted-foreground">Project</dt>
                        <!--
                            A link in the reading colour with an underline on hover — the same
                            treatment every other in-content link in this app has. Not
                            `text-primary`: the shell already spends the screen's one orange on
                            the active nav rail, and a page's primary button spends the other
                            (DESIGN.md §5.3).
                        -->
                        <dd class="flex min-w-0 flex-col items-start gap-1 text-sm break-words">
                            <Link
                                v-if="task.project"
                                :href="`/${surface}/projects/${task.project.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ task.project.name }}
                            </Link>
                            <template v-else>—</template>
                            <!--
                                The move itself is a dialog, not this button: it has a
                                consequence for the task's tags that has to be said before
                                the click rather than found on the timeline afterwards.

                                Outline, like every other control on this screen — and
                                deliberately not the `link` variant, which is `text-primary`:
                                the shell already spends the screen's one orange on the active
                                nav rail (DESIGN.md §5.3), which is the same reason the project
                                name above is a reading-coloured link.
                            -->
                            <Button
                                v-if="mayMove"
                                ref="moveOpener"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="mt-1"
                                @click="openMove"
                            >
                                <FolderInput aria-hidden="true" />
                                Move to another project…
                            </Button>
                        </dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <dt class="text-xs font-medium text-muted-foreground">Priority</dt>
                        <dd class="min-w-0 text-sm">{{ task.priority_label ?? '—' }}</dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <dt class="text-xs font-medium text-muted-foreground">Start date</dt>
                        <dd class="min-w-0 text-sm tabular-nums">{{ formatDate(task.start_date) }}</dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <dt class="text-xs font-medium text-muted-foreground">Due date</dt>
                        <!-- Late prints its word; red alone says nothing in greyscale (§5.6). -->
                        <dd :class="cn('min-w-0 text-sm tabular-nums', task.is_overdue && 'text-destructive')">
                            {{ formatDate(task.due_date) }}
                            <span v-if="task.is_overdue" class="text-xs font-medium">· Overdue</span>
                        </dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <dt class="text-xs font-medium text-muted-foreground">Estimate</dt>
                        <dd class="min-w-0 text-sm tabular-nums">{{ formatMinutes(task.estimated_minutes) }}</dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <dt class="text-xs font-medium text-muted-foreground">Created by</dt>
                        <dd class="min-w-0 text-sm break-words">{{ task.created_by?.name ?? '—' }}</dd>
                    </div>
                </dl>

                <!--
                    Assigning a tag is an edit, not tag CRUD: the options are the ones the
                    controller sent, which is exactly what `tag_ids` accepts. Nothing here
                    creates, renames or deletes one.
                -->
                <div class="flex min-w-0 flex-col gap-2">
                    <p id="task-tags-label" class="text-xs font-medium text-muted-foreground">Tags</p>

                    <p v-if="task.tags.length === 0" class="text-sm text-muted-foreground">No tags.</p>
                    <ul v-else class="flex min-w-0 flex-wrap items-center gap-1" aria-labelledby="task-tags-label">
                        <li v-for="tag in task.tags" :key="tag.id" class="flex items-center gap-0.5">
                            <StatusBadge :status="tagTone(tag.colour)" :label="tag.name" size="sm" />
                            <Button
                                v-if="mayTag"
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                class="shrink-0"
                                :disabled="busyTag === tag.id"
                                :aria-label="`Remove the tag ${tag.name}`"
                                @click="removeTag(tag.id)"
                            >
                                <X aria-hidden="true" />
                            </Button>
                        </li>
                    </ul>

                    <div v-if="mayTag" class="flex min-w-0 flex-wrap items-end gap-2">
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <Label for="task-tag-add" class="sr-only">Add a tag</Label>
                            <!--
                                Grouped, because the two kinds behave differently and the
                                difference only shows up later: a tag of this project's own
                                is left behind when the task moves, a global one travels.
                            -->
                            <Select v-model="chosenTag" :disabled="tagging || full || untagged.length === 0">
                                <SelectTrigger id="task-tag-add" ref="tagTrigger" class="w-full sm:w-64">
                                    <SelectValue placeholder="Add a tag…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup v-if="scopedChoices.length > 0">
                                        <SelectLabel>
                                            {{ task.project?.name ?? 'This project' }} only
                                        </SelectLabel>
                                        <SelectItem
                                            v-for="tag in scopedChoices"
                                            :key="tag.id"
                                            :value="String(tag.id)"
                                        >
                                            {{ tag.name }}
                                        </SelectItem>
                                    </SelectGroup>
                                    <SelectGroup v-if="globalChoices.length > 0">
                                        <SelectLabel>Every project</SelectLabel>
                                        <SelectItem
                                            v-for="tag in globalChoices"
                                            :key="tag.id"
                                            :value="String(tag.id)"
                                        >
                                            {{ tag.name }}
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="tagging || chosenTag === '' || full"
                            @click="addTag"
                        >
                            <Plus aria-hidden="true" />
                            {{ tagging ? 'Adding…' : 'Add tag' }}
                        </Button>
                    </div>

                    <p v-if="mayTag && full" class="text-xs text-muted-foreground">
                        A task takes at most {{ MAX_TAGS }} tags. Remove one to add another.
                    </p>
                    <p
                        v-else-if="mayTag && untagged.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        Every tag this project can use is already on the task.
                    </p>
                    <p v-if="tagError" class="text-xs text-destructive">{{ tagError }}</p>
                </div>

                <div v-if="editable">
                    <Button ref="opener" type="button" size="sm" variant="outline" @click="edit">
                        <Pencil aria-hidden="true" />
                        Edit details
                    </Button>
                </div>
            </template>
        </CardContent>
    </Card>

    <Dialog v-model:open="moveOpen">
        <DialogContent class="max-w-lg">
            <form novalidate @submit.prevent="move">
                <DialogHeader>
                    <DialogTitle>Move this task to another project?</DialogTitle>
                    <DialogDescription>
                        It leaves {{ task.project?.name ?? 'its project' }} and its position is taken
                        from the bottom of the column it lands in.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex min-w-0 flex-col gap-4 py-4">
                    <div class="flex min-w-0 flex-col gap-2">
                        <Label for="task-move-project">
                            New project <span class="text-destructive" aria-hidden="true">*</span>
                            <span class="sr-only">(required)</span>
                        </Label>
                        <Select v-model="moveTo" :disabled="moving">
                            <SelectTrigger id="task-move-project" class="w-full">
                                <SelectValue placeholder="Pick a project…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="project in otherProjects"
                                    :key="project.id"
                                    :value="String(project.id)"
                                >
                                    {{ project.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <!--
                        The consequence, before the click. The server detaches every tag scoped
                        to the old project and writes the line on the timeline; discovering that
                        afterwards is discovering it too late, so the tags that would go are
                        named here. No colour carries this on its own — it is the sentence
                        (DESIGN.md §5.6).
                    -->
                    <div v-if="scopedOnTask.length > 0" class="flex min-w-0 flex-col gap-2 rounded-md border p-3">
                        <p class="text-sm font-medium">
                            {{ scopedOnTask.length === 1 ? 'This tag is removed by the move' : 'These tags are removed by the move' }}
                        </p>
                        <div class="flex flex-wrap items-center gap-1">
                            <StatusBadge
                                v-for="tag in scopedOnTask"
                                :key="tag.id"
                                :status="tagTone(tag.colour)"
                                :label="tag.name"
                                size="sm"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ scopedOnTask.length === 1 ? 'It belongs' : 'They belong' }} to
                            {{ task.project?.name ?? 'this project' }} alone, so the move leaves
                            {{ scopedOnTask.length === 1 ? 'it' : 'them' }} behind. Tags that every
                            project can use stay on the task, and its timeline records what was dropped.
                        </p>
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        Nothing on this task is scoped to
                        {{ task.project?.name ?? 'this project' }}, so no tag is lost — a tag that only
                        one project can use would be, and the timeline would record it.
                    </p>

                    <p v-if="moveError" class="text-xs text-destructive">{{ moveError }}</p>
                    <p v-if="formErrors.project_id" class="text-xs text-destructive">
                        {{ formErrors.project_id }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="moving" @click="moveOpen = false">
                        Keep it here
                    </Button>
                    <Button type="submit" :disabled="moving">
                        {{ moving ? 'Moving…' : 'Move the task' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
