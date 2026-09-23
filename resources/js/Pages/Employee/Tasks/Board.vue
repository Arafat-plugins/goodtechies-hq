<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageShell from '@/Components/PageShell.vue';
import TaskBoard from '@/Components/Tasks/TaskBoard.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskViewSwitcher from '@/Components/Tasks/TaskViewSwitcher.vue';
import type { BoardPayload, TransitionMap } from '@/Components/Tasks/taskBoard';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';

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

/** Refusals come back 200 with a flashed sentence. The toaster says it once (§5.19). */
useFlashAsToast();
</script>

<template>
    <Head title="My Tasks — Board" />

    <PageShell title="My Tasks" description="Your work, in the lane it is sitting in.">
        <template #tabs>
            <TaskViewSwitcher surface="employee" current="board" />
        </template>

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
        />
    </PageShell>
</template>
