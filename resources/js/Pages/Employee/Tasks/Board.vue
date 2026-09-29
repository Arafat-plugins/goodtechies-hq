<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import TaskBoard from '@/Components/Tasks/TaskBoard.vue';
import TaskDetailDrawer from '@/Components/Tasks/TaskDetailDrawer.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskViewSwitcher from '@/Components/Tasks/TaskViewSwitcher.vue';
import type { BoardPayload, TransitionMap } from '@/Components/Tasks/taskBoard';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';
import { queryParam } from '@/lib/tableState';

defineOptions({ layout: EmployeeLayout });

/**
 * The same board, scoped by `Task::visibleTo()` rather than by this page.
 *
 * No `employees` prop and so no assignee chip: every card here is already this person's. The
 * `transitions` map is narrower too — an employee's role cannot reach Completed or Cancelled
 * from anywhere — which is what makes those columns visibly not drop targets the moment a card
 * is picked up, instead of accepting the drop and having the server undo it.
 */
defineProps<{
    board: BoardPayload;
    transitions: TransitionMap;
    filters: TaskFilters;
    statuses: TaskOption[];
    /** The bucket chip's options — "what is late", "what is due today". Server-labelled. */
    buckets: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
    /** `TagPolicy::create`, answered by the controller — whether the tag manager is offered. */
    canManageTags: boolean;
}>();

/*
 * Part 0.5 refresh rule (somebody else moves a card) is kept by `TaskBoard`'s own
 * `useLiveTaskProps(['board'])` — event-driven on `tasks.{user}`, held during a drag or a pan
 * (flow F1, decision 12-69). A second, page-level refresh for the same prop had no drag guard and
 * could replace the columns mid-drag, so it was removed (reliability slice 3).
 */

/** Refusals come back 200 with a flashed sentence. The toaster says it once (§5.19). */
useFlashAsToast();

/* ------------------------------------------------------------------ drawer */

/**
 * A card opens the task beside the board, the List's drawer exactly (`TaskDetailDrawer`). It writes
 * `?detail=<id>` with `history.replaceState`, so opening and closing it is no Inertia visit: the
 * board's props, lanes and scroll stay as they are.
 *
 * Read in setup, NOT in `onMounted` — `DetailDrawer` clears `?detail=` from an `immediate` watcher
 * before an `onMounted` here could see it (DESIGN.md §4.3). The state lives here, outside the
 * board and keyed on nothing it sends, so a refresh of `board` cannot close or reset the drawer.
 */
const deepLink = Number(queryParam('detail'));
const opensDeepLinked = Number.isFinite(deepLink) && deepLink > 0;

const detailId = ref<number | null>(opensDeepLinked ? deepLink : null);
const drawerOpen = ref(opensDeepLinked);

function openTask(taskId: number): void {
    detailId.value = taskId;
    drawerOpen.value = true;
}
</script>

<template>
    <Head title="My Tasks — Board" />

    <PageShell title="Tasks" title-hidden>
        <TaskBoard
            :board="board"
            :transitions="transitions"
            :filters="filters"
            surface="employee"
            :statuses="statuses"
            :buckets="buckets"
            :priorities="priorities"
            :projects="projects"
            :tags="tags"
            :can-manage-tags="canManageTags"
            search-placeholder="Search your tasks…"
            empty-title="No tasks assigned to you"
            empty-description="When someone assigns you work, it lands here."
            @open-task="openTask"
        >
            <template #toolbar-leading>
                <TaskViewSwitcher surface="employee" current="board" />
            </template>
        </TaskBoard>
    </PageShell>

    <TaskDetailDrawer v-model:open="drawerOpen" :task-id="detailId" surface="employee" />
</template>
