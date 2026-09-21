<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import type { TaskNamedRef, TaskOption } from '@/Components/Tasks/TaskList.vue';
import { focusField } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
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
import { RadioGroup, RadioGroupItem } from '@/Components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';

/**
 * The quick-add task modal — `docs/design-refs/08-new-task-modal.png`.
 *
 * The reference's shape is kept: two tabs, the name first and unlabelled-by-a-heading, then
 * the place it belongs, then the description, with Cancel and Add in the footer. What it calls
 * a "space" this app does not have, so **Task** is name · project · description and
 * **Details** holds the rest of what `StoreTaskRequest` accepts — priority (which it requires),
 * the two dates, an estimate, and up to two assignees with one of them primary.
 *
 * A task may be born in Backlog or To do and nowhere else (`TaskService::BIRTH_STATUSES`);
 * every move after that is the status machine's. There is no status control beyond those two.
 *
 * At 360 the dialog is the full width of the viewport minus the page gutter, its body scrolls,
 * and the two footer buttons stack — the reference's own phone frame.
 */

const MAX_ASSIGNEES = 2;

const props = defineProps<{
    open: boolean;
    projects: TaskNamedRef[];
    priorities: TaskOption[];
    /** The Admin list is the only screen with an employee list; without it, no assignee step. */
    employees?: TaskNamedRef[];
    /** Preselect the project when the modal is opened from inside one. */
    projectId?: number | null;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean]; created: [] }>();

const DEFAULT_PRIORITY = 'medium';

const form = useForm<{
    project_id: string;
    title: string;
    description: string;
    status: string;
    priority: string;
    start_date: string;
    due_date: string;
    estimated_minutes: string;
    assignee_ids: number[];
    primary_assignee_id: string;
}>({
    project_id: '',
    title: '',
    description: '',
    status: 'todo',
    priority: DEFAULT_PRIORITY,
    start_date: '',
    due_date: '',
    estimated_minutes: '',
    assignee_ids: [],
    primary_assignee_id: '',
});

const tab = ref('task');
const titleField = ref<{ $el?: unknown } | null>(null);

const defaultPriority = computed(
    () => props.priorities.find((option) => option.value === DEFAULT_PRIORITY)?.value ?? props.priorities[0]?.value ?? '',
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();
        form.priority = defaultPriority.value;
        form.project_id = props.projectId ? String(props.projectId) : '';
        tab.value = 'task';
    },
);

/**
 * Reka autofocuses the dialog's first focusable thing, which is the Task tab. The name is
 * what somebody opened this to type, so that is where the caret goes.
 */
function focusName(event: Event): void {
    event.preventDefault();
    void nextTick(() => focusField(titleField.value));
}

const chosenPeople = computed(() =>
    (props.employees ?? []).filter((employee) => form.assignee_ids.includes(employee.id)),
);

function toggleAssignee(id: number, on: boolean): void {
    if (on) {
        if (!form.assignee_ids.includes(id)) {
            form.assignee_ids = [...form.assignee_ids, id];
        }
    } else {
        form.assignee_ids = form.assignee_ids.filter((value) => value !== id);
    }

    if (form.primary_assignee_id !== '' && !form.assignee_ids.includes(Number(form.primary_assignee_id))) {
        form.primary_assignee_id = '';
    }

    // One person on a task is that person's task; the radio only matters from two.
    if (form.assignee_ids.length === 1) {
        form.primary_assignee_id = String(form.assignee_ids[0]);
    }
}

/** Which tab holds the field the server complained about, so the error is never off-screen. */
const DETAIL_FIELDS = [
    'priority',
    'start_date',
    'due_date',
    'estimated_minutes',
    'assignee_ids',
    'primary_assignee_id',
    'status',
];

function submit(): void {
    if (form.processing) {
        return;
    }

    form
        .transform((data) => ({
            project_id: data.project_id === '' ? null : Number(data.project_id),
            title: data.title.trim(),
            description: data.description.trim() === '' ? null : data.description.trim(),
            status: data.status,
            priority: data.priority,
            start_date: data.start_date === '' ? null : data.start_date,
            due_date: data.due_date === '' ? null : data.due_date,
            estimated_minutes:
                data.estimated_minutes.trim() === '' ? null : Number(data.estimated_minutes),
            assignee_ids: data.assignee_ids,
            primary_assignee_id:
                data.primary_assignee_id === '' ? null : Number(data.primary_assignee_id),
        }))
        .post('/admin/tasks', {
            // Creating redirects to the new task's OWN page — which is where somebody who
            // just wrote one wants to be — so this is a real visit, not a preserved-state
            // patch. The "Task created." the server flashed is announced by whichever screen
            // holds the flash channel, exactly like every other task write.
            onSuccess: () => {
                emit('update:open', false);
                emit('created');
            },
            onError: (errors) => {
                if (Object.keys(errors).some((key) => DETAIL_FIELDS.includes(key))) {
                    tab.value = 'details';
                }
            },
        });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="flex max-h-[90svh] w-[calc(100vw-2rem)] max-w-lg flex-col gap-0 p-0 sm:w-full"
            @open-auto-focus="focusName"
        >
            <form class="flex min-h-0 flex-col" novalidate @submit.prevent="submit">
                <DialogHeader class="gap-1 border-b p-4 pr-12 text-left sm:p-6 sm:pr-12">
                    <DialogTitle class="text-xl">New task</DialogTitle>
                    <DialogDescription>
                        It starts in Backlog or To do; every move after that goes through the board.
                    </DialogDescription>
                </DialogHeader>

                <Tabs v-model="tab" class="min-h-0 flex-1 gap-0 overflow-y-auto">
                    <TabsList class="mx-4 mt-4 w-auto self-start sm:mx-6">
                        <TabsTrigger value="task">Task</TabsTrigger>
                        <TabsTrigger value="details">Details</TabsTrigger>
                    </TabsList>

                    <TabsContent value="task" class="flex min-w-0 flex-col gap-4 p-4 sm:p-6">
                        <div class="flex min-w-0 flex-col gap-2">
                            <Label for="quick-task-title">
                                Name <span class="text-destructive" aria-hidden="true">*</span>
                            </Label>
                            <Input
                                id="quick-task-title"
                                ref="titleField"
                                v-model="form.title"
                                placeholder="Write task name…"
                                :disabled="form.processing"
                                :aria-invalid="form.errors.title ? true : undefined"
                            />
                            <p v-if="form.errors.title" class="text-xs text-destructive">
                                {{ form.errors.title }}
                            </p>
                        </div>

                        <div class="flex min-w-0 flex-col gap-2">
                            <Label for="quick-task-project">
                                Project <span class="text-destructive" aria-hidden="true">*</span>
                            </Label>
                            <Select v-model="form.project_id">
                                <SelectTrigger
                                    id="quick-task-project"
                                    class="w-full"
                                    :aria-invalid="form.errors.project_id ? true : undefined"
                                >
                                    <SelectValue placeholder="Select a project…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="project in projects"
                                        :key="project.id"
                                        :value="String(project.id)"
                                    >
                                        {{ project.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="form.errors.project_id" class="text-xs text-destructive">
                                {{ form.errors.project_id }}
                            </p>
                        </div>

                        <div class="flex min-w-0 flex-col gap-2">
                            <Label for="quick-task-description">Description</Label>
                            <Textarea
                                id="quick-task-description"
                                v-model="form.description"
                                rows="5"
                                placeholder="Write task description…"
                                :disabled="form.processing"
                            />
                            <p v-if="form.errors.description" class="text-xs text-destructive">
                                {{ form.errors.description }}
                            </p>
                        </div>
                    </TabsContent>

                    <TabsContent value="details" class="flex min-w-0 flex-col gap-4 p-4 sm:p-6">
                        <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="quick-task-status">Starts in</Label>
                                <Select v-model="form.status">
                                    <SelectTrigger id="quick-task-status" class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="backlog">Backlog</SelectItem>
                                        <SelectItem value="todo">To do</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="quick-task-priority">
                                    Priority <span class="text-destructive" aria-hidden="true">*</span>
                                </Label>
                                <Select v-model="form.priority">
                                    <SelectTrigger id="quick-task-priority" class="w-full">
                                        <SelectValue />
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
                                <p v-if="form.errors.priority" class="text-xs text-destructive">
                                    {{ form.errors.priority }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="quick-task-start">Start date</Label>
                                <Input
                                    id="quick-task-start"
                                    v-model="form.start_date"
                                    type="date"
                                    :disabled="form.processing"
                                />
                                <p v-if="form.errors.start_date" class="text-xs text-destructive">
                                    {{ form.errors.start_date }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="quick-task-due">Due date</Label>
                                <Input
                                    id="quick-task-due"
                                    v-model="form.due_date"
                                    type="date"
                                    :disabled="form.processing"
                                />
                                <p v-if="form.errors.due_date" class="text-xs text-destructive">
                                    {{ form.errors.due_date }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-2">
                                <Label for="quick-task-estimate">Estimate (minutes)</Label>
                                <Input
                                    id="quick-task-estimate"
                                    v-model="form.estimated_minutes"
                                    type="number"
                                    min="0"
                                    :disabled="form.processing"
                                />
                                <p v-if="form.errors.estimated_minutes" class="text-xs text-destructive">
                                    {{ form.errors.estimated_minutes }}
                                </p>
                            </div>
                        </div>

                        <div v-if="employees?.length" class="flex min-w-0 flex-col gap-2">
                            <p class="text-sm font-medium">Assignees</p>
                            <p class="text-xs text-muted-foreground">
                                At most {{ MAX_ASSIGNEES }}. The primary owns completion — the work
                                summary the task is finished on has to be theirs.
                            </p>
                            <ul class="grid max-h-40 gap-2 overflow-y-auto sm:grid-cols-2">
                                <li
                                    v-for="employee in employees"
                                    :key="employee.id"
                                    class="flex items-center gap-2"
                                >
                                    <Checkbox
                                        :id="`quick-task-assignee-${employee.id}`"
                                        :model-value="form.assignee_ids.includes(employee.id)"
                                        :disabled="
                                            form.processing ||
                                            (form.assignee_ids.length >= MAX_ASSIGNEES &&
                                                !form.assignee_ids.includes(employee.id))
                                        "
                                        @update:model-value="(value) => toggleAssignee(employee.id, value === true)"
                                    />
                                    <Label
                                        :for="`quick-task-assignee-${employee.id}`"
                                        class="min-w-0 font-normal"
                                    >
                                        {{ employee.name }}
                                    </Label>
                                </li>
                            </ul>
                            <p v-if="form.errors.assignee_ids" class="text-xs text-destructive">
                                {{ form.errors.assignee_ids }}
                            </p>

                            <div v-if="chosenPeople.length > 1" class="flex min-w-0 flex-col gap-2 pt-2">
                                <p class="text-xs font-medium text-muted-foreground">Primary assignee</p>
                                <RadioGroup v-model="form.primary_assignee_id" class="gap-2">
                                    <div
                                        v-for="person in chosenPeople"
                                        :key="person.id"
                                        class="flex items-center gap-2"
                                    >
                                        <RadioGroupItem
                                            :id="`quick-task-primary-${person.id}`"
                                            :value="String(person.id)"
                                            :disabled="form.processing"
                                        />
                                        <Label
                                            :for="`quick-task-primary-${person.id}`"
                                            class="font-normal"
                                        >
                                            {{ person.name }}
                                        </Label>
                                    </div>
                                </RadioGroup>
                                <p v-if="form.errors.primary_assignee_id" class="text-xs text-destructive">
                                    {{ form.errors.primary_assignee_id }}
                                </p>
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>

                <DialogFooter class="flex-col gap-2 border-t p-4 sm:flex-row sm:justify-end sm:p-6">
                    <Button
                        type="button"
                        variant="outline"
                        class="w-full sm:w-auto"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" class="w-full sm:w-auto" :disabled="form.processing">
                        {{ form.processing ? 'Adding…' : 'Add' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
