<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import QuickAddTaskModal from '@/Components/Tasks/QuickAddTaskModal.vue';
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
import { Button } from '@/Components/ui/button';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';
import { queryParam, syncQuery } from '@/lib/tableState';

defineOptions({ layout: AdminLayout });

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
    /** Slice 1 had no list to build the assignee chip from; slice 2's controller sends one. */
    employees: TaskNamedRef[];
}>();

/**
 * The drawer's writes are this screen's writes, and a drawer covers the layout's alert strip
 * outright — so the flash is spoken by the toaster here too (DESIGN.md §5.19).
 */
useFlashAsToast();

/**
 * The seven columns the plan names, in its order: Name · Assignee · Status · Due date ·
 * Time tracked · Tags · Subtask count.
 */
const columns = computed(() =>
    taskColumns(['title', 'assignee', 'status', 'due_date', 'tracked', 'tags', 'subtask_count']),
);

/* ------------------------------------------------------------------ drawer */

/**
 * A row opens the task beside its list rather than instead of it. The drawer writes
 * `?detail=<id>` while it is open, so the view somebody is looking at is the view they can
 * send; this reads that parameter back.
 *
 * Read in setup, NOT in `onMounted`. `DetailDrawer` syncs the URL from a watcher with
 * `immediate: true`, and a child's setup runs before its parent's `onMounted` — so a drawer
 * that starts closed clears `?detail=` from the address bar before an `onMounted` here could
 * ever see it, and a pasted deep link opens nothing.
 */
const deepLink = Number(queryParam('detail'));
const opensDeepLinked = Number.isFinite(deepLink) && deepLink > 0;

const detailId = ref<number | null>(opensDeepLinked ? deepLink : null);
const drawerOpen = ref(opensDeepLinked);

/**
 * The top bar's + menu has no modal of its own to open from every page, so its Task row lands
 * here with `?new=1`. Same idea as `?detail=`: what is shareable goes in the URL, and the
 * parameter is taken back out once it has been acted on so a refresh does not reopen it.
 */
const quickAddOpen = ref(queryParam('new') !== null);

if (quickAddOpen.value) {
    syncQuery({ new: null });
}

function openTask(task: Task): void {
    detailId.value = task.id;
    drawerOpen.value = true;
}
</script>

<template>
    <Head title="Tasks" />

    <PageShell title="Tasks" description="Every task across the agency, grouped however you read it.">
        <template #tabs>
            <TaskViewSwitcher surface="admin" current="list" />
        </template>

        <template #actions>
            <Button type="button" @click="quickAddOpen = true">
                <Plus aria-hidden="true" />
                New task
            </Button>
        </template>

        <TaskList
            table-id="admin-tasks"
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
            :employees="employees"
            search-placeholder="Search tasks…"
            empty-title="No tasks yet"
            empty-description="Tasks appear here as soon as work is planned on a project."
            @row-click="openTask"
        />
    </PageShell>

    <TaskDetailDrawer v-model:open="drawerOpen" :task-id="detailId" surface="admin" />

    <!-- Creating redirects to the new task's page, so there is nothing to refresh here. -->
    <QuickAddTaskModal
        v-model:open="quickAddOpen"
        :projects="projects"
        :priorities="priorities"
        :employees="employees"
    />
</template>
