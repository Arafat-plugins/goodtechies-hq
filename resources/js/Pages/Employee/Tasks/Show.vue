<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import PageShell from '@/Components/PageShell.vue';
import type { TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskDetailBody from '@/Components/Tasks/TaskDetailBody.vue';
import type { TaskActivityEntry, TaskDetail } from '@/Components/Tasks/taskDetail';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';

defineOptions({ layout: EmployeeLayout });

/**
 * The same task detail, on the employee surface.
 *
 * The body is the identical component the Admin page mounts. What differs is what the
 * controller sends and what the policy allows, not what this screen draws: there is no
 * `employees` list (assignment is a management decision and its endpoint is not on this
 * surface), no `priorities` and no `siblings` (dependencies are the plan), and
 * `task.available_transitions` stops at In review because a reviewer is never the person
 * whose work is being reviewed.
 *
 * `tags` it does get: `PUT /employee/tasks/{task}` takes `tag_ids`, because assigning an
 * existing label is work rather than plan. There is no `projects` list, because `project_id`
 * is `prohibited` here and a picker offering a move the request refuses is a lie in the UI.
 *
 * A task this employee is not assigned to never reaches here — `Task::visibleTo()` plus
 * `firstOrFail()` answer 404, not 403, because a record they may not see must read as absent.
 */
defineProps<{
    task: TaskDetail;
    activity: TaskActivityEntry[];
    reviewers: { id: number; name: string | null }[];
    /** This project's tags plus the global ones — exactly what `tag_ids` accepts. */
    tags: TaskTag[];
}>();

/** The flash is spoken by the toaster here, exactly as on the Admin page. */
useFlashAsToast();
</script>

<template>
    <Head :title="task.title" />

    <PageShell
        :title="task.title"
        :description="task.project?.name ? `In ${task.project.name}` : undefined"
        :breadcrumb="[{ label: 'My Tasks', href: '/employee/tasks' }, { label: task.title }]"
    >
        <TaskDetailBody
            :task="task"
            :activity="activity"
            surface="employee"
            :reviewers="reviewers"
            :tags="tags"
            variant="page"
            @removed="router.visit('/employee/tasks')"
        />
    </PageShell>
</template>
