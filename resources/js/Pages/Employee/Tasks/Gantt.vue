<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageShell from '@/Components/PageShell.vue';
import type { GanttPayload, GanttZoomOption } from '@/Components/Tasks/Gantt/gantt';
import TaskGantt from '@/Components/Tasks/Gantt/TaskGantt.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskViewSwitcher from '@/Components/Tasks/TaskViewSwitcher.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';

defineOptions({ layout: EmployeeLayout });

/**
 * The same timeline, with `can_plan` false for an Employee and true for the Manager who
 * shares this surface.
 *
 * `TaskService::mayPlan()` is the one definition behind both that flag and the `prohibited`
 * rule `UpdateTaskRequest` puts on `start_date` and `due_date`, so the bars being read-only
 * here and the write being refused there cannot come apart. Which tasks are on it at all is
 * `Task::visibleTo()`'s answer and nothing this page does.
 */
defineProps<{
    gantt: GanttPayload;
    can_plan: boolean;
    filters: TaskFilters;
    zooms: GanttZoomOption[];
    statuses: TaskOption[];
    /** The bucket chip's options — "what is late", "what is due today". Server-labelled. */
    buckets: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
    /** `TagPolicy::create`, answered by the controller — whether the tag manager is offered. */
    canManageTags: boolean;
}>();

useFlashAsToast();
</script>

<template>
    <Head title="My Tasks — Gantt" />

    <PageShell title="My Tasks" description="What you owe when, project by project, across the weeks.">
        <template #tabs>
            <TaskViewSwitcher surface="employee" current="gantt" />
        </template>

        <TaskGantt
            :gantt="gantt"
            :can-plan="can_plan"
            surface="employee"
            :filters="filters"
            :zooms="zooms"
            :statuses="statuses"
            :buckets="buckets"
            :priorities="priorities"
            :projects="projects"
            :tags="tags"
            :can-manage-tags="canManageTags"
            search-placeholder="Search your tasks…"
            empty-title="Nothing of yours is scheduled in this window"
            empty-description="Move the window or zoom out — a task with no dates is not on a timeline."
        />
    </PageShell>
</template>
