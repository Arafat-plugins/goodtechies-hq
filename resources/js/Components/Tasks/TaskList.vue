<script lang="ts">
import type { ColumnDef } from '@/Components/DataTable/types';
import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The Tasks List view: the grouped `DataTable`, its chip filter bar and its group-by toggle.
 *
 * Admin and Employee render the same list of the same shape — the backend already decided
 * which rows each of them may see, and which group-by variants mean anything on their
 * surface. What the two screens differ in is which columns they offer, so the columns are a
 * prop and everything else lives here once.
 */

/** A person as every task payload names them: an id and a display name, nothing more. */
export interface TaskPerson {
    id: number;
    name: string | null;
}

/**
 * An assignee, as `TaskResource::employee()` sends one.
 *
 * `id` is the EMPLOYEE's — it is what the assignee endpoints take back — and `user_id` is the
 * user behind that employee row, which is a different thing and therefore a different key. The
 * pair is what lets the screen make the comparison the server makes: completion tests
 * `work_summary_by` (a USER) against the primary assignee's `user_id`, and two people with the
 * same display name are not the same person.
 */
export interface TaskEmployeeRef extends TaskPerson {
    user_id: number | null;
}

export interface TaskAssignee extends TaskEmployeeRef {
    is_primary: boolean;
}

/** `colour` is a `StatusKey` token name resolved by `app.css`, never a literal colour. */
export interface TaskTag {
    id: number;
    name: string;
    colour: string;
    is_global: boolean;
}

/** One task, exactly as `TaskResource` sends it (tests/Feature/Resources/TaskResourceTest.php). */
export interface Task {
    id: number;
    title: string;
    description: string | null;
    status: string;
    status_label: string;
    /** Resolved server-side. The screen never maps a status to a colour. */
    status_tone: StatusKey;
    priority: string;
    priority_label: string;
    start_date: string | null;
    due_date: string | null;
    /** Computed against the request's as-of date, never a stored column. */
    is_overdue: boolean;
    estimated_minutes: number | null;
    tracked_seconds: number;
    position: number;
    archived_at: string | null;
    is_archived: boolean;
    work_summary: string | null;
    completed_at: string | null;
    completed_by: TaskPerson | null;
    created_at: string;
    created_by: TaskPerson | null;
    /** A `ProjectResource` fragment — it decides its own fields per requester. */
    project?: { id: number; name: string } | null;
    assignees: TaskAssignee[];
    primary_assignee: TaskEmployeeRef | null;
    tags: TaskTag[];
    subtask_count: number;
    permissions: {
        can_update: boolean;
        can_delete: boolean;
        can_archive: boolean;
        can_review: boolean;
    };
}

export interface TaskGroup {
    key: string;
    label: string;
    tone: StatusKey | null;
    count: number;
    tasks: Task[];
}

/**
 * The grouped envelope.
 *
 * `total` counts tasks. Under `group_by=assignee` a two-person task is in both of their
 * groups, so the group counts sum to more than `total` — which is correct, and is why this
 * screen never prints a sum of the groups anywhere.
 */
export interface TaskGroups {
    group_by: string;
    total: number;
    overdue_count: number;
    groups: TaskGroup[];
}

export interface TaskFilters {
    search: string | null;
    project_id: number | null;
    status: string | null;
    priority: string | null;
    assignee_id: number | null;
    tag_id: number | null;
    overdue: boolean;
    archived: boolean;
}

export interface TaskOption {
    value: string;
    label: string;
}

export interface TaskNamedRef {
    id: number;
    name: string;
}

/** Every column the List view knows how to render, by key. */
const TASK_COLUMNS: Record<string, ColumnDef<Task>> = {
    title: { key: 'title', header: 'Name', hideable: false },
    assignee: {
        key: 'assignee',
        header: 'Assignee',
        value: (task) => task.primary_assignee?.name ?? null,
        nowrap: true,
    },
    status: { key: 'status', header: 'Status', cell: 'badge', nowrap: true },
    due_date: { key: 'due_date', header: 'Due date', cell: 'date', nowrap: true },
    tracked: {
        key: 'tracked',
        header: 'Time tracked',
        cell: 'number',
        value: (task) => task.tracked_seconds,
        nowrap: true,
    },
    tags: { key: 'tags', header: 'Tags' },
    /**
     * Starts hidden, for two reasons that both stop being true in slice 2. It reads 0 on
     * every row until checklists exist, and seven columns is 69 px more than the table's
     * scroll box has at 768 — which is a sideways scroll inside a card, the exact defect
     * Phase 0.5's close-out found on `/profile`. It is in the Columns menu from day one, so
     * a viewer who wants it gets it and their choice is already persisted when real counts
     * arrive.
     */
    subtask_count: { key: 'subtask_count', header: 'Subtasks', cell: 'number', defaultHidden: true },
};

/**
 * The columns a surface offers, in the order it offers them.
 *
 * No column is `sortable` and no screen shows rows-per-page: `TaskService::query()` orders
 * by due date, then the manual board position, then id, and reads neither `sort`/`dir` nor
 * `per_page`. A header that pushed one would silently do nothing (decisions 0.5-16, 0.5-19).
 */
export function taskColumns(keys: string[]): ColumnDef<Task>[] {
    return keys.map((key) => TASK_COLUMNS[key]).filter((column): column is ColumnDef<Task> => column !== undefined);
}

const GROUP_BY_LABELS: Record<string, string> = {
    status: 'Status',
    assignee: 'Assignee',
    project: 'Project',
    priority: 'Priority',
};

/** Tracked time reads as hours and minutes; seconds are noise on a list. */
export function formatTracked(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds <= 0) {
        return '—';
    }

    const minutes = Math.round(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest}m`;
    }

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

const STATUS_KEYS: StatusKey[] = [
    'backlog',
    'todo',
    'progress',
    'review',
    'changes',
    'done',
    'waiting',
    'cancelled',
];

/**
 * A tag names its colour as a `StatusKey`. A value outside the union would render a badge
 * with no token behind it, so an unknown one falls back to the neutral tone rather than to
 * a transparent pill nobody can see.
 */
export function tagTone(colour: string): StatusKey {
    return STATUS_KEYS.includes(colour as StatusKey) ? (colour as StatusKey) : 'todo';
}
</script>

<script setup lang="ts">
import { ListChecks } from '@lucide/vue';
import { computed } from 'vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { TableGroup } from '@/Components/DataTable/types';
import type { FilterDef } from '@/Components/FilterBar.vue';
import FilterBar from '@/Components/FilterBar.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Checkbox } from '@/Components/ui/checkbox';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { pushQuery, resetQuery } from '@/lib/tableState';
import { useNavigationPending } from '@/lib/useNavigationPending';
import { cn } from '@/lib/utils';

const props = defineProps<{
    tasks: TaskGroups;
    filters: TaskFilters;
    /** Built with `taskColumns()`; the surface decides which of them it offers. */
    columns: ColumnDef<Task>[];
    /** Stable per surface — column, density and collapsed-group preferences hang off it. */
    tableId: string;
    /** The variants this surface offers. The employee list has no `assignee`. */
    groupByOptions: string[];
    statuses: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
    /**
     * The assignee filter's options — the Admin list only. `TaskService` has taken an
     * `assignee_id` filter since slice 1; this is the list that makes the chip offerable
     * (follow-up 2-8). The employee surface is sent none on purpose: every row there is
     * already theirs, so the filter would have exactly one value.
     */
    employees?: TaskNamedRef[];
    searchPlaceholder: string;
    emptyTitle: string;
    emptyDescription: string;
}>();

defineEmits<{ 'row-click': [task: Task] }>();

/* ----------------------------------------------------------------- filters */

/** The filters `TaskService::filters()` reads, offered where the option list exists. */
const filterDefs = computed<FilterDef[]>(() => {
    const defs: FilterDef[] = [
        { key: 'status', label: 'Status', kind: 'select', options: props.statuses },
        { key: 'priority', label: 'Priority', kind: 'select', options: props.priorities },
        {
            key: 'project_id',
            label: 'Project',
            kind: 'select',
            options: props.projects.map((project) => ({ value: String(project.id), label: project.name })),
            searchPlaceholder: 'Search projects…',
        },
        {
            key: 'tag_id',
            label: 'Tag',
            kind: 'select',
            options: props.tags.map((tag) => ({ value: String(tag.id), label: tag.name })),
            searchPlaceholder: 'Search tags…',
        },
    ];

    if (props.employees?.length) {
        defs.splice(2, 0, {
            key: 'assignee_id',
            label: 'Assignee',
            kind: 'select',
            options: props.employees.map((employee) => ({
                value: String(employee.id),
                label: employee.name,
            })),
            searchPlaceholder: 'Search people…',
        });
    }

    return defs;
});

const hasFilters = computed(
    () =>
        Boolean(props.filters.search) ||
        props.filters.status !== null ||
        props.filters.priority !== null ||
        props.filters.project_id !== null ||
        props.filters.tag_id !== null ||
        props.filters.assignee_id !== null ||
        props.filters.overdue ||
        props.filters.archived,
);

function clearFilters(): void {
    // Group-by is a way of reading the list, not a filter on it, so Clear all keeps it.
    resetQuery(['group_by']);
}

/* ---------------------------------------------------------------- grouping */

const groupByChoices = computed(() =>
    props.groupByOptions.map((value) => ({ value, label: GROUP_BY_LABELS[value] ?? value })),
);

function setGroupBy(value: unknown): void {
    if (typeof value === 'string' && value !== props.tasks.group_by) {
        pushQuery({ group_by: value });
    }
}

/** The payload's `tasks` becomes the table's `rows`; everything else already matches. */
const groups = computed<TableGroup<Task>[]>(() =>
    props.tasks.groups.map((group) => ({
        key: group.key,
        label: group.label,
        tone: group.tone,
        count: group.count,
        rows: group.tasks,
    })),
);

/* ------------------------------------------------------------------- cells */

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE.format(date);
}

function initials(name: string | null): string {
    return (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('');
}

/** Everyone on the task who is not the primary — the "+2" after the primary's name. */
function extraAssignees(task: Task): number {
    return Math.max(task.assignees.length - 1, 0);
}

const loading = useNavigationPending();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <FilterBar
            :search="filters.search"
            :filters="filterDefs"
            :extra-active="filters.overdue || filters.archived"
            :placeholder="searchPlaceholder"
            :input-id="`${tableId}-search`"
        >
            <template #extra>
                <div class="flex h-9 items-center gap-2">
                    <Checkbox
                        :id="`${tableId}-overdue`"
                        :model-value="filters.overdue"
                        @update:model-value="(checked) => pushQuery({ overdue: checked === true })"
                    />
                    <Label :for="`${tableId}-overdue`" class="font-normal whitespace-nowrap">Overdue only</Label>
                </div>
                <div class="flex h-9 items-center gap-2">
                    <Checkbox
                        :id="`${tableId}-archived`"
                        :model-value="filters.archived"
                        @update:model-value="(checked) => pushQuery({ archived: checked === true })"
                    />
                    <Label :for="`${tableId}-archived`" class="font-normal whitespace-nowrap">Show archived</Label>
                </div>
            </template>
        </FilterBar>

        <DataTable
            :id="tableId"
            :columns="columns"
            :groups="groups"
            :group-by="tasks.group_by"
            :loading="loading"
            :filters-active="hasFilters"
            :row-label="(task) => task.title"
            noun="task"
            row-clickable
            :empty-icon="ListChecks"
            :empty-title="emptyTitle"
            :empty-description="emptyDescription"
            filtered-title="No tasks match these filters"
            filtered-description="Clear a filter, or widen the search."
            @clear="clearFilters"
            @row-click="(task) => $emit('row-click', task)"
        >
            <template #toolbar>
                <div class="flex items-center gap-2">
                    <Label :for="`${tableId}-group-by`" class="text-xs font-normal text-muted-foreground">
                        Group by
                    </Label>
                    <Select :model-value="tasks.group_by" @update:model-value="setGroupBy">
                        <SelectTrigger
                            :id="`${tableId}-group-by`"
                            size="sm"
                            class="w-32"
                            aria-label="Group tasks by"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="choice in groupByChoices"
                                :key="choice.value"
                                :value="choice.value"
                            >
                                {{ choice.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!--
                    `total` counts tasks, not group memberships: under group-by-assignee a
                    two-person task is in two groups and the counts deliberately sum higher.
                -->
                <p class="text-xs text-muted-foreground">
                    <span class="tabular-nums">{{ tasks.total }}</span>
                    {{ tasks.total === 1 ? 'task' : 'tasks' }}
                    <template v-if="tasks.overdue_count > 0">
                        ·
                        <span class="font-medium text-destructive">
                            <span class="tabular-nums">{{ tasks.overdue_count }}</span> overdue
                        </span>
                    </template>
                </p>
            </template>

            <template #cell-title="{ row }">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <span class="font-medium break-words">{{ row.title }}</span>
                    <span
                        v-if="row.is_archived"
                        class="rounded-full border px-2 py-0.5 text-xs font-medium text-muted-foreground"
                    >
                        Archived
                    </span>
                </div>
            </template>

            <template #cell-assignee="{ row }">
                <span v-if="row.primary_assignee" class="inline-flex items-center gap-2">
                    <Avatar class="size-6">
                        <AvatarFallback class="text-xs">
                            {{ initials(row.primary_assignee.name) }}
                        </AvatarFallback>
                    </Avatar>
                    {{ row.primary_assignee.name ?? '—' }}
                    <!-- The word, not a bare "+2": a count with no noun is a puzzle. -->
                    <span v-if="extraAssignees(row) > 0" class="text-xs text-muted-foreground">
                        +{{ extraAssignees(row) }} more
                    </span>
                </span>
                <span v-else class="text-muted-foreground">Unassigned</span>
            </template>

            <!-- The tone is the server's answer; this screen only paints it. -->
            <template #cell-status="{ row }">
                <StatusBadge :status="row.status_tone" :label="row.status_label" />
            </template>

            <!--
                Late is a status, so it prints its word — the same treatment as the deadline
                cell on Admin/Projects/Index.vue. Red alone says nothing to a screen reader
                and nothing in greyscale, and decision 2-5 records that `review` and `waiting`
                are all but identical under deuteranopia, so colour carries the emphasis and
                the word carries the meaning. `is_overdue` is the server's, never recomputed.
            -->
            <template #cell-due_date="{ row }">
                <span :class="cn('flex flex-col', row.is_overdue && 'text-destructive')">
                    <span>{{ formatDate(row.due_date) }}</span>
                    <span v-if="row.is_overdue" class="text-xs font-medium">Overdue</span>
                </span>
            </template>

            <template #cell-tracked="{ row }">
                {{ formatTracked(row.tracked_seconds) }}
            </template>

            <template #cell-tags="{ row }">
                <span v-if="row.tags.length > 0" class="flex flex-wrap items-center gap-1">
                    <StatusBadge
                        v-for="tag in row.tags"
                        :key="tag.id"
                        :status="tagTone(tag.colour)"
                        :label="tag.name"
                        size="sm"
                    />
                </span>
                <span v-else class="text-muted-foreground">—</span>
            </template>

            <template #cell-subtask_count="{ row }">
                {{ row.subtask_count }}
            </template>
        </DataTable>
    </div>
</template>
