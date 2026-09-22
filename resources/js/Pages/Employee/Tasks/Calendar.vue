<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageShell from '@/Components/PageShell.vue';
import type { CalendarPayload } from '@/Components/Tasks/TaskCalendar.vue';
import TaskCalendar from '@/Components/Tasks/TaskCalendar.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskViewSwitcher from '@/Components/Tasks/TaskViewSwitcher.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';

defineOptions({ layout: EmployeeLayout });

/**
 * The same grid, with `can_plan` false.
 *
 * `TaskService::mayPlan()` is the one definition behind both that flag and the `prohibited`
 * rule `UpdateTaskRequest` puts on `start_date` and `due_date`, so the handles being disabled
 * here and the write being refused there cannot come apart.
 */
defineProps<{
    calendar: CalendarPayload;
    can_plan: boolean;
    filters: TaskFilters;
    statuses: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
}>();

useFlashAsToast();
</script>

<template>
    <Head title="My Tasks — Calendar" />

    <PageShell title="My Tasks" description="What you owe when, and what runs across the weeks.">
        <template #tabs>
            <TaskViewSwitcher surface="employee" current="calendar" />
        </template>

        <TaskCalendar
            :calendar="calendar"
            :can-plan="can_plan"
            surface="employee"
            :filters="filters"
            :statuses="statuses"
            :priorities="priorities"
            :projects="projects"
            :tags="tags"
            search-placeholder="Search your tasks…"
            empty-title="Nothing of yours is scheduled in this window"
            empty-description="Move to another month — a task with no dates is not on a grid."
        />
    </PageShell>
</template>
