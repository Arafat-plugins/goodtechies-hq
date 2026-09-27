<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import type { GanttPayload, GanttZoomOption } from '@/Components/Tasks/Gantt/gantt';
import TaskGantt from '@/Components/Tasks/Gantt/TaskGantt.vue';
import QuickAddTaskModal from '@/Components/Tasks/QuickAddTaskModal.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskViewSwitcher from '@/Components/Tasks/TaskViewSwitcher.vue';
import { Button } from '@/Components/ui/button';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';
import { queryParam, syncQuery } from '@/lib/tableState';

defineOptions({ layout: AdminLayout });

defineProps<{
    gantt: GanttPayload;
    /** Date drags are Admin/Manager only — `TaskService::mayPlan()`, the one definition. */
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
    employees: TaskNamedRef[];
}>();

/** A date drag is a write that can be refused with a sentence; the toaster says it once. */
useFlashAsToast();

const quickAddOpen = ref(queryParam('new') !== null);

if (quickAddOpen.value) {
    syncQuery({ new: null });
}
</script>

<template>
    <Head title="Tasks — Gantt" />

    <PageShell title="Tasks" description="What runs when, project by project, and what waits on what.">
        <template #tabs>
            <TaskViewSwitcher surface="admin" current="gantt" />
        </template>

        <template #actions>
            <Button type="button" @click="quickAddOpen = true">
                <Plus aria-hidden="true" />
                New task
            </Button>
        </template>

        <TaskGantt
            :gantt="gantt"
            :can-plan="can_plan"
            surface="admin"
            :filters="filters"
            :zooms="zooms"
            :statuses="statuses"
            :buckets="buckets"
            :priorities="priorities"
            :projects="projects"
            :tags="tags"
            :can-manage-tags="canManageTags"
            :employees="employees"
            search-placeholder="Search tasks…"
            empty-title="Nothing scheduled in this window"
            empty-description="Move the window, zoom out, or give a task a start or due date."
        />
    </PageShell>

    <QuickAddTaskModal
        v-model:open="quickAddOpen"
        :projects="projects"
        :priorities="priorities"
        :employees="employees"
    />
</template>
