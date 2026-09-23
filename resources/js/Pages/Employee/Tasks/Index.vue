<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import TaskDetailDrawer from '@/Components/Tasks/TaskDetailDrawer.vue';
import type {
    Task,
    TaskFilters,
    TaskGroups,
    TaskNamedRef,
    TaskOption,
    TaskTag,
} from '@/Components/Tasks/TaskList.vue';
import TaskList, { taskColumns } from '@/Components/Tasks/TaskList.vue';
import TaskViewSwitcher from '@/Components/Tasks/TaskViewSwitcher.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';
import { queryParam } from '@/lib/tableState';

defineOptions({ layout: EmployeeLayout });

defineProps<{
    tasks: TaskGroups;
    filters: TaskFilters;
    groupByOptions: string[];
    statuses: TaskOption[];
    /** The bucket chip's options — "what is late", "what is due today". Server-labelled. */
    buckets: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
    /** `TagPolicy::create`, answered by the controller — whether the tag manager is offered. */
    canManageTags: boolean;
}>();

/** The drawer's writes are this screen's writes; the toaster announces them. */
useFlashAsToast();

/**
 * The same table, two columns lighter.
 *
 * **Assignee** goes. Every row here is already this employee's — `Task::visibleTo()` scopes
 * the list to what they are assigned to, which is why `Employee\TaskController::GROUP_BY`
 * offers no assignee variant ("grouping by assignee would produce exactly one group"). A
 * column that prints the reader's own name on every row is not information.
 *
 * **The ⋯ actions column** never appears, because nothing would go in it. Every verb it
 * could hold is reported false in this surface's `permissions` block — `can_delete`,
 * `can_archive` and `can_review` are all false for an employee on their own task
 * (TaskResourceTest, "mirrors the policy in permissions"). The one true, `can_update`, is
 * status and work, which slice 2 owns; it has no route to point at today.
 *
 * This stays a table rather than the card grid the employee project list uses: decision
 * 0.5-20 chose cards there because an employee reads five projects by domain, and a task
 * list is the spreadsheet-shaped thing a table is for. Below `md` the card fallback that
 * `DataTable` already owns handles the phone.
 */
const columns = computed(() => taskColumns(['title', 'status', 'due_date', 'tracked', 'tags', 'subtask_count']));

/**
 * A row opens the task beside the list. Same drawer, same body, same rules as the Admin
 * surface — `surface` only picks which routes the writes go to, and the policy decides the
 * rest. No assignee filter here and no employee list: every row is already this person's.
 */
const deepLink = Number(queryParam('detail'));
const opensDeepLinked = Number.isFinite(deepLink) && deepLink > 0;

/* Read in setup, not `onMounted` — see the note on the Admin list. */
const detailId = ref<number | null>(opensDeepLinked ? deepLink : null);
const drawerOpen = ref(opensDeepLinked);

function openTask(task: Task): void {
    detailId.value = task.id;
    drawerOpen.value = true;
}
</script>

<template>
    <Head title="My Tasks" />

    <PageShell title="My Tasks" description="The work assigned to you, and where each piece stands.">
        <template #tabs>
            <TaskViewSwitcher surface="employee" current="list" />
        </template>

        <TaskList
            table-id="employee-tasks"
            :tasks="tasks"
            :filters="filters"
            :columns="columns"
            :group-by-options="groupByOptions"
            :statuses="statuses"
            :buckets="buckets"
            :priorities="priorities"
            :projects="projects"
            :tags="tags"
            :can-manage-tags="canManageTags"
            search-placeholder="Search your tasks…"
            empty-title="No tasks assigned to you"
            empty-description="When someone assigns you work, it lands here."
            @row-click="openTask"
        />
    </PageShell>

    <TaskDetailDrawer v-model:open="drawerOpen" :task-id="detailId" surface="employee" />
</template>
