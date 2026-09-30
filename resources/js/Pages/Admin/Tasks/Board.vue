<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import QuickAddTaskModal from '@/Components/Tasks/QuickAddTaskModal.vue';
import TaskBoard from '@/Components/Tasks/TaskBoard.vue';
import TaskDetailDrawer from '@/Components/Tasks/TaskDetailDrawer.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskViewSwitcher from '@/Components/Tasks/TaskViewSwitcher.vue';
import type { BoardPayload, TransitionMap } from '@/Components/Tasks/taskBoard';
import { Button } from '@/Components/ui/button';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';
import { queryParam, syncQuery } from '@/lib/tableState';

defineOptions({ layout: AdminLayout });

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
    employees: TaskNamedRef[];
}>();

/*
 * Part 0.5 refresh rule (somebody else moves a card) is kept by `TaskBoard`'s own
 * `useLiveTaskProps(['board'])` — event-driven on `tasks.{user}`, held during a drag or a pan
 * (flow F1, decision 12-69). A second, page-level refresh for the same prop had no drag guard and
 * could replace the columns mid-drag, so it was removed (reliability slice 3).
 */

/**
 * Every move on this screen is a server round trip that answers with a sentence, refusals
 * included — a `TaskStateException` comes back 200 with a flashed `error`. The toaster says it
 * once (DESIGN.md §5.19): while this page is mounted `FlashMessage` draws nothing, so a
 * refused drop does not leave an alert strip above a board the reader has already scrolled
 * past sideways.
 */
useFlashAsToast();

/** The top bar's + menu has no project list, so its Task row lands here with `?new=1`. */
const quickAddOpen = ref(queryParam('new') !== null);

if (quickAddOpen.value) {
    syncQuery({ new: null });
}

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
    <Head title="Tasks — Board" />

    <PageShell title="Tasks" title-hidden bleed>
        <TaskBoard
            :board="board"
            :transitions="transitions"
            :filters="filters"
            surface="admin"
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
            @open-task="openTask"
        >
            <template #toolbar-leading>
                <TaskViewSwitcher surface="admin" current="board" />
            </template>
            <template #toolbar-trailing>
                <Button type="button" @click="quickAddOpen = true">
                    <Plus aria-hidden="true" />
                    New task
                </Button>
            </template>
        </TaskBoard>
    </PageShell>

    <TaskDetailDrawer v-model:open="drawerOpen" :task-id="detailId" surface="admin" />

    <QuickAddTaskModal
        v-model:open="quickAddOpen"
        :projects="projects"
        :priorities="priorities"
        :employees="employees"
    />
</template>
