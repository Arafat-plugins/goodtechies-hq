<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import PageShell from '@/Components/PageShell.vue';
import QuickAddTaskModal from '@/Components/Tasks/QuickAddTaskModal.vue';
import TaskBoard from '@/Components/Tasks/TaskBoard.vue';
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
</script>

<template>
    <Head title="Tasks — Board" />

    <PageShell title="Tasks" description="Every task across the agency, in the lane it is sitting in.">
        <template #tabs>
            <TaskViewSwitcher surface="admin" current="board" />
        </template>

        <template #actions>
            <Button type="button" @click="quickAddOpen = true">
                <Plus aria-hidden="true" />
                New task
            </Button>
        </template>

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
        />
    </PageShell>

    <QuickAddTaskModal
        v-model:open="quickAddOpen"
        :projects="projects"
        :priorities="priorities"
        :employees="employees"
    />
</template>
