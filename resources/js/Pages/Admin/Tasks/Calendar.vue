<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import QuickAddTaskModal from '@/Components/Tasks/QuickAddTaskModal.vue';
import type { CalendarPayload } from '@/Components/Tasks/TaskCalendar.vue';
import TaskCalendar from '@/Components/Tasks/TaskCalendar.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskViewSwitcher from '@/Components/Tasks/TaskViewSwitcher.vue';
import { Button } from '@/Components/ui/button';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';
import { queryParam, syncQuery } from '@/lib/tableState';

defineOptions({ layout: AdminLayout });

defineProps<{
    calendar: CalendarPayload;
    /** Date drags are Admin/Manager only — `TaskService::mayPlan()`, the one definition. */
    can_plan: boolean;
    filters: TaskFilters;
    statuses: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
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
    <Head title="Tasks — Calendar" />

    <PageShell title="Tasks" description="What is due when, and what is running across the weeks.">
        <template #tabs>
            <TaskViewSwitcher surface="admin" current="calendar" />
        </template>

        <template #actions>
            <Button type="button" @click="quickAddOpen = true">
                <Plus aria-hidden="true" />
                New task
            </Button>
        </template>

        <TaskCalendar
            :calendar="calendar"
            :can-plan="can_plan"
            surface="admin"
            :filters="filters"
            :statuses="statuses"
            :priorities="priorities"
            :projects="projects"
            :tags="tags"
            :employees="employees"
            search-placeholder="Search tasks…"
            empty-title="Nothing scheduled in this window"
            empty-description="Move to another month, or give a task a start or due date."
        />
    </PageShell>

    <QuickAddTaskModal
        v-model:open="quickAddOpen"
        :projects="projects"
        :priorities="priorities"
        :employees="employees"
    />
</template>
