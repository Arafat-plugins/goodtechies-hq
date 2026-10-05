<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { FormDataConvertible } from '@inertiajs/core';
import { FolderInput, Pencil, Plus, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EstimateInput from '@/Components/Tasks/EstimateInput.vue';
import type { TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import { tagTone } from '@/Components/Tasks/TaskList.vue';
import type { TaskDetail, TaskSurface } from '@/Components/Tasks/taskDetail';
import { focusField, formatDate, formatMinutes, mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
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
import { linkify } from '@/lib/linkify';
import { useUnsavedGuard } from '@/lib/unsavedGuard';
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

const draft = ref<{
    title: string;
    description: string;
    priority: string;
    start_date: string;
    due_date: string;
    estimated_minutes: number | null;
}>({
    title: '',
    description: '',
    priority: '',
    start_date: '',
    due_date: '',
    estimated_minutes: null,
});

function edit(): void {
    draft.value = {
        title: props.task.title,
        description: props.task.description ?? '',
        priority: props.task.priority ?? '',
        start_date: props.task.start_date ?? '',
        due_date: props.task.due_date ?? '',
        estimated_minutes: props.task.estimated_minutes ?? null,
    };
    draftBaseline = JSON.stringify(draft.value);
    editing.value = true;
    void nextTick(() => focusField(firstField.value));
}

/**
 * Reliability slice 3: an open inline edit whose fields differ from what it opened with asks
 * before a navigation or an F5 throws the change away. Cancel and a saved edit both close it.
 */
let draftBaseline = '';

useUnsavedGuard(() => editing.value && JSON.stringify(draft.value) !== draftBaseline);

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
        estimated_minutes: draft.value.estimated_minutes,
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

/** The rows' label column and their borderless-until-hover controls. */
const rowLabel = 'self-start py-1 text-xs font-medium text-muted-foreground';
const rowControl = 'h-7 border-transparent px-2 shadow-flat hover:border-input dark:bg-transparent';

const formErrors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

/* ------------------------------------------------------------- in-place rows */

/**
 * Brief 014: the Asana-style rows write one field at a time, through the same `PUT` the
 * "Edit details" form sends — `UpdateTaskRequest` takes a partial body (tags and the move
 * already rely on it). Plan fields are offered in place only to someone who may plan.
 */
const mayPlanHere = computed(() => mayPlan.value && editable.value);
const savingField = ref<string | null>(null);

function saveField(field: 'due_date' | 'priority' | 'description', value: string | null): void {
    if (savingField.value !== null) {
        return;
    }

    savingField.value = field;

    mutateTask('put', routes.value.update, { [field]: value }, {
        onAccepted: () => {
            if (field === 'description') {
                descriptionBaseline.value = description.value;
            }
        },
        onSettled: () => emit('settled'),
        onFinish: () => {
            savingField.value = null;
        },
    });
}

function changeDue(event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    if (value !== (props.task.due_date ?? '')) {
        saveField('due_date', value === '' ? null : value);
    }
}

function changePriority(value: unknown): void {
    if (typeof value === 'string' && value !== '' && value !== props.task.priority) {
        saveField('priority', value);
    }
}

/**
 * The description is plain text that turns into a field when clicked, and saves on blur.
 * A live re-read (flow F1) replaces it only while nobody is typing in it — typed text is safe.
 */
const description = ref(props.task.description ?? '');
const descriptionBaseline = ref(description.value);
const descriptionFocused = ref(false);
const descriptionDirty = computed(() => description.value !== descriptionBaseline.value);

watch(
    () => props.task.description,
    (value) => {
        if (!descriptionFocused.value && !descriptionDirty.value) {
            description.value = value ?? '';
            descriptionBaseline.value = description.value;
        }
    },
);

useUnsavedGuard(() => descriptionDirty.value);

/** Brief 025: the description reads as linked text until it is clicked or tabbed onto. */
const descriptionEditing = ref(false);
const descriptionField = ref<{ $el?: unknown } | null>(null);
const descriptionSegments = computed(() => linkify(editable.value ? description.value : (props.task.description ?? '')));

function editDescription(): void {
    if (savingField.value === 'description') {
        return;
    }

    descriptionEditing.value = true;
    void nextTick(() => focusField(descriptionField.value));
}

/** A click on one of the links follows it; anywhere else on the text starts the edit. */
function onDescriptionClick(event: MouseEvent): void {
    if (event.target instanceof Element && event.target.closest('a') !== null) {
        return;
    }

    editDescription();
}

function blurDescription(): void {
    descriptionFocused.value = false;
    descriptionEditing.value = false;

    if (descriptionDirty.value) {
        saveField('description', blank(description.value));
    }
}

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
    <!--
        Brief 014: the Asana-style label | value rows. No card, no prose subline — the rows are
        the panel. Due date, priority and the description are edited in place (one field per
        `PUT`); the title, start date and estimate stay behind "Edit details".
    -->
    <section class="flex min-w-0 flex-col gap-3" data-task-fields>
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
                    <Label for="task-estimate">Estimate</Label>
                    <EstimateInput id="task-estimate" v-model="draft.estimated_minutes" :disabled="saving" />
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
            <dl class="grid min-w-0 grid-cols-[6rem_minmax(0,1fr)] items-center gap-x-3 gap-y-0.5 text-sm">
                <template v-if="$slots.assignee">
                    <dt :class="rowLabel">Assignee</dt>
                    <dd class="min-w-0"><slot name="assignee" /></dd>
                </template>

                <dt :class="rowLabel"><label for="task-row-due">Due date</label></dt>
                <dd class="flex min-w-0 flex-wrap items-center gap-2">
                    <Input
                        v-if="mayPlanHere"
                        id="task-row-due"
                        type="date"
                        :model-value="task.due_date ?? ''"
                        :disabled="savingField === 'due_date'"
                        :class="cn(rowControl, 'w-auto tabular-nums', task.is_overdue && 'text-destructive')"
                        data-row-due
                        @change="changeDue"
                    />
                    <span v-else :class="cn('py-0.5 tabular-nums', task.is_overdue && 'text-destructive')">
                        {{ formatDate(task.due_date) }}
                    </span>
                    <!-- Late prints its word; red alone says nothing in greyscale (§5.6). -->
                    <span v-if="task.is_overdue" class="text-xs font-medium text-destructive">Overdue</span>
                </dd>

                <dt :class="rowLabel"><label for="task-row-priority">Priority</label></dt>
                <dd class="min-w-0">
                    <Select
                        v-if="mayPlanHere && priorities?.length"
                        :model-value="task.priority ?? ''"
                        :disabled="savingField === 'priority'"
                        @update:model-value="changePriority"
                    >
                        <SelectTrigger id="task-row-priority" size="sm" :class="cn(rowControl, 'w-auto')" data-row-priority>
                            <SelectValue placeholder="—" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="priority in priorities" :key="priority.value" :value="priority.value">
                                {{ priority.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <span v-else class="py-0.5">{{ task.priority_label ?? '—' }}</span>
                </dd>

                <dt :class="rowLabel">Project</dt>
                <!--
                    A link in the reading colour with an underline on hover — not `text-primary`:
                    the shell already spends the screen's one orange (DESIGN.md §5.3). The move
                    is a dialog because it has a consequence for the task's tags.
                -->
                <dd class="flex min-w-0 flex-wrap items-center gap-1 break-words">
                    <Link
                        v-if="task.project"
                        :href="`/${surface}/projects/${task.project.id}`"
                        class="py-0.5 font-medium hover:underline"
                    >
                        {{ task.project.name }}
                    </Link>
                    <span v-else class="py-0.5">—</span>
                    <Button
                        v-if="mayMove"
                        ref="moveOpener"
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Move to another project…"
                        title="Move to another project…"
                        @click="openMove"
                    >
                        <FolderInput aria-hidden="true" />
                    </Button>
                </dd>

                <dt :class="rowLabel">Start date</dt>
                <dd class="min-w-0 py-0.5 tabular-nums">{{ formatDate(task.start_date) }}</dd>

                <dt :class="rowLabel">Estimate</dt>
                <dd class="min-w-0 py-0.5 tabular-nums">{{ formatMinutes(task.estimated_minutes) }}</dd>

                <!--
                    Assigning a tag is an edit, not tag CRUD: the options are the ones the
                    controller sent, which is exactly what `tag_ids` accepts. Nothing here
                    creates, renames or deletes one.
                -->
                <dt id="task-tags-label" :class="rowLabel">Tags</dt>
                <dd class="flex min-w-0 flex-col gap-1.5 py-0.5">

                    <p v-if="task.tags.length === 0 && !mayTag" class="text-sm text-muted-foreground">—</p>
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
                                <SelectTrigger id="task-tag-add" ref="tagTrigger" size="sm" class="w-full sm:w-56">
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
                </dd>

                <dt :class="rowLabel">Created by</dt>
                <dd class="min-w-0 py-0.5 break-words">{{ task.created_by?.name ?? '—' }}</dd>
            </dl>

            <div v-if="editable" class="-mt-2">
                <Button ref="opener" type="button" size="sm" variant="ghost" class="text-muted-foreground" @click="edit">
                    <Pencil aria-hidden="true" />
                    Edit details
                </Button>
            </div>

            <div class="flex min-w-0 flex-col gap-1">
                <h3 class="text-sm font-semibold"><label for="task-row-description">Description</label></h3>
                <!--
                    Brief 025: read as text whose URLs are links (`lib/linkify.ts` splits it; each
                    piece is a text node or an `<a>` built here, never `v-html`), and edited as
                    the same plain text: a click on the text — not on a link — or Tab onto it
                    turns it into the field, which saves on blur exactly as before.
                -->
                <Textarea
                    v-if="editable && descriptionEditing"
                    id="task-row-description"
                    ref="descriptionField"
                    v-model="description"
                    rows="3"
                    placeholder="What is this task about?"
                    :disabled="savingField === 'description'"
                    class="field-sizing-content min-h-16 resize-none px-2 shadow-flat"
                    data-row-description
                    @focus="descriptionFocused = true"
                    @blur="blurDescription"
                />
                <div
                    v-else-if="editable"
                    id="task-row-description"
                    role="textbox"
                    aria-multiline="true"
                    aria-label="Description"
                    tabindex="0"
                    class="min-h-16 min-w-0 cursor-text rounded-md border border-transparent px-2 py-2 text-sm break-words whitespace-pre-line shadow-flat outline-none hover:border-input focus-visible:ring-3 focus-visible:ring-ring"
                    data-row-description-view
                    @click="onDescriptionClick"
                    @focus="editDescription"
                >
                    <template v-if="description.trim() !== ''">
                        <template v-for="(segment, index) in descriptionSegments" :key="index">
                            <a
                                v-if="segment.kind === 'link'"
                                :href="segment.href"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-medium break-all underline underline-offset-4"
                                >{{ segment.text }}</a
                            ><template v-else>{{ segment.text }}</template>
                        </template>
                    </template>
                    <span v-else class="text-muted-foreground">What is this task about?</span>
                </div>
                <p v-else-if="task.description" class="min-w-0 text-sm break-words whitespace-pre-line">
                    <template v-for="(segment, index) in descriptionSegments" :key="index">
                        <a
                            v-if="segment.kind === 'link'"
                            :href="segment.href"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-medium break-all underline underline-offset-4"
                            >{{ segment.text }}</a
                        ><template v-else>{{ segment.text }}</template>
                    </template>
                </p>
                <p v-else class="text-sm text-muted-foreground">No description.</p>
                <p v-if="formErrors.description" class="text-xs text-destructive">{{ formErrors.description }}</p>
            </div>
        </template>
    </section>

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
