<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import PageShell from '@/Components/PageShell.vue';
import LiveTaskStatus from '@/Components/Realtime/LiveTaskStatus.vue';
import type { TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskDetailBody from '@/Components/Tasks/TaskDetailBody.vue';
import type {
    TaskActivityEntry,
    TaskDetail,
    TaskDiscussion,
    TaskSibling,
} from '@/Components/Tasks/taskDetail';
import TaskTimerButton from '@/Components/Timer/TaskTimerButton.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useFlashAsToast } from '@/lib/flashChannel';

defineOptions({ layout: AdminLayout });

/**
 * The Admin task detail page.
 *
 * It is a mount, not a screen: every panel, every verb and every rule lives in
 * `TaskDetailBody`, which the Tasks List mounts inside `DetailDrawer` from a row click. The
 * two are the same component because a task that reads one way beside its list and another way
 * on its own page is two screens to keep in step.
 *
 * The moves this requester may make are `task.available_transitions`, already filtered through
 * `TaskPolicy::transition`; the full list of statuses would be a second, wider answer to the
 * same question, so this page neither receives nor declares one.
 */
defineProps<{
    task: TaskDetail;
    activity: TaskActivityEntry[];
    /**
     * The task's thread, inlined by the controller so the discussion panel paints with it
     * rather than with a spinner. The same shape the drawer mount is handed.
     */
    discussion: TaskDiscussion;
    employees: TaskNamedRef[];
    reviewers: { id: number; name: string | null }[];
    priorities: TaskOption[];
    siblings: TaskSibling[];
    /** The tag picker's options: this project's tags plus the global ones. */
    tags: TaskTag[];
    /** Where this task may be moved to. Admin only — `project_id` is prohibited elsewhere. */
    projects: TaskNamedRef[];
}>();

/**
 * This screen announces its own writes, so the server's flash is spoken by the toaster and
 * the layout's alert strip stays quiet — one message, one channel (DESIGN.md §5.19).
 */
useFlashAsToast();
</script>

<template>
    <Head :title="task.title" />

    <PageShell
        :title="task.title"
        :breadcrumb="[{ label: 'Tasks', href: '/admin/tasks' }, { label: task.title }]"
    >
        <!-- Brief 025: the task's own timer at the top right, as in the drawer's header. The
             project is named once, by the Project row in the body. -->
        <template v-if="task.permissions.can_track_time || task.my_timer" #actions>
            <TaskTimerButton :task-id="task.id" :my-timer="task.my_timer ?? null" variant="panel" />
        </template>

        <TaskDetailBody
            :task="task"
            :activity="activity"
            :discussion="discussion"
            surface="admin"
            :reviewers="reviewers"
            :employees="employees"
            :priorities="priorities"
            :siblings="siblings"
            :tags="tags"
            :projects="projects"
            variant="page"
            @removed="router.visit('/admin/tasks')"
        />

        <!-- The live half of this page: `task.{id}` on a socket build, a fifteen-second
             re-read without one, and either way it is the SERVER's answer that paints —
             the frame carries the task's id and nothing else. See the component. -->
        <LiveTaskStatus :task-id="task.id" :status="task.status" />
    </PageShell>
</template>
