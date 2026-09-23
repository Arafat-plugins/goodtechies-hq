<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Repeat, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import FilePanel from '@/Components/Files/FilePanel.vue';
import type { FileRoutes } from '@/Components/Files/files';
import { fileRoutes } from '@/Components/Files/files';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import { formatTracked } from '@/Components/Tasks/TaskList.vue';
import TaskActivityPanel from '@/Components/Tasks/TaskActivityPanel.vue';
import TaskChecklistPanel from '@/Components/Tasks/TaskChecklistPanel.vue';
import TaskDependenciesPanel from '@/Components/Tasks/TaskDependenciesPanel.vue';
import TaskDiscussionPanel from '@/Components/Tasks/TaskDiscussionPanel.vue';
import TaskFieldsPanel from '@/Components/Tasks/TaskFieldsPanel.vue';
import TaskLinksPanel from '@/Components/Tasks/TaskLinksPanel.vue';
import TaskPeoplePanel from '@/Components/Tasks/TaskPeoplePanel.vue';
import TaskStatusActions from '@/Components/Tasks/TaskStatusActions.vue';
import TaskSummaryPanel from '@/Components/Tasks/TaskSummaryPanel.vue';
import type {
    TaskActivityEntry,
    TaskDetail,
    TaskDiscussion,
    TaskSibling,
    TaskSurface,
} from '@/Components/Tasks/taskDetail';
import { formatDateTime, mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

/**
 * One task, rendered once and mounted twice.
 *
 * `Pages/{Admin,Employee}/Tasks/Show.vue` hangs this under a `PageShell`; the Tasks List hangs
 * the same component inside `DetailDrawer` when a row is clicked. Nothing below knows which it
 * is beyond `variant`, which only decides how many columns the panels sit in — a drawer is
 * 448 px wide, so a two-column grid there would be two half-columns.
 *
 * `surface` picks which set of routes to write to. It is never what decides what a person may
 * do: that is `task.permissions` and `task.available_transitions`, both resolved by the policy
 * on the server, and the endpoint checks again regardless of what this screen offers.
 */

const props = withDefaults(
    defineProps<{
        task: TaskDetail;
        activity: TaskActivityEntry[];
        /**
         * The thread, inlined by both detail controllers so the panel paints with it. The panel
         * refreshes itself from `…/discussion` after that — the same shape, one builder.
         */
        discussion: TaskDiscussion;
        surface: TaskSurface;
        reviewers: { id: number; name: string | null }[];
        /** Admin detail only: the assignee picker's options. */
        employees?: TaskNamedRef[];
        /** Admin detail only. */
        priorities?: TaskOption[];
        /** Admin detail only: the dependency picker's options. */
        siblings?: TaskSibling[];
        /** Both surfaces: the tag picker's options — what `tag_ids` accepts on this task. */
        tags?: TaskTag[];
        /** Admin detail only: where the task may be moved to. `project_id` is prohibited elsewhere. */
        projects?: TaskNamedRef[];
        variant?: 'page' | 'drawer';
    }>(),
    { variant: 'page' },
);

const emit = defineEmits<{
    /** A write landed and the caller should make sure it is looking at fresh data. */
    settled: [];
    /** The task is gone; a drawer showing it should close. */
    removed: [];
}>();

const routes = computed(() => taskRoutes(props.surface, props.task.id));

/**
 * The attachment panel's URLs.
 *
 * Branched rather than passed through, because `fileRoutes` is overloaded on purpose: the
 * employee surface has the task file routes and nothing else, so the narrow call is what keeps
 * "an employee project's files" a compile error instead of a 404 somebody finds in staging.
 */
const attachmentRoutes = computed<FileRoutes>(() =>
    props.surface === 'admin'
        ? fileRoutes('admin', 'tasks', props.task.id)
        : fileRoutes('employee', 'tasks', props.task.id),
);

const people = ref<InstanceType<typeof TaskPeoplePanel> | null>(null);

/** The Approve alert's "Hand it over…" opens the panel's own dialog, not a second copy. */
function handOff(): void {
    people.value?.openHandOff();
}

/* ------------------------------------------------------ archive and delete */

/** Unarchive is Admin-only and lives on the Admin surface; the employee routes have none. */
const mayUnarchive = computed(() => props.surface === 'admin' && props.task.permissions.can_archive);

const archiving = ref(false);

function toggleArchive(): void {
    if (archiving.value) {
        return;
    }

    archiving.value = true;

    mutateTask('post', props.task.is_archived ? routes.value.unarchive : routes.value.archive, {}, {
        onSettled: () => emit('settled'),
        onFinish: () => {
            archiving.value = false;
        },
    });
}

const deleteOpen = ref(false);
const deleting = ref(false);

/**
 * A soft delete, so the history survives. It redirects to the list rather than staying on a
 * page for a record that is no longer there, which is why it does not go through
 * `mutateTask()` — there is nothing left to refresh.
 */
function confirmDelete(): void {
    if (deleting.value) {
        return;
    }

    deleting.value = true;

    router.delete(routes.value.destroy, {
        preserveScroll: false,
        onSuccess: () => {
            deleteOpen.value = false;
            emit('removed');
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <!-- Where the task stands, and what it can do next. -->
        <div class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <StatusBadge :status="task.status_tone" :label="task.status_label" />
                <span
                    v-if="task.is_archived"
                    class="rounded-full border px-2 py-0.5 text-xs font-medium text-muted-foreground"
                >
                    Archived
                </span>
                <span
                    v-if="task.is_overdue"
                    class="rounded-full border px-2 py-0.5 text-xs font-medium text-destructive"
                >
                    Overdue
                </span>
                <span class="text-xs text-muted-foreground">{{ task.priority_label }} priority</span>
            </div>

            <!--
                Phase 3: where this task came from. Both mounts get it, because there is one
                body — the page under `PageShell` and the drawer over the List are the same
                component, so "on both surfaces and both mounts" is one line rather than four.

                The link is `can_manage`, which is `RecurringTaskPolicy::view` resolved per
                record on the server. An assignee reads the sentence and is offered no way into
                a screen that would refuse them; nothing here looks at a role.
            -->
            <p
                v-if="task.generated_from"
                class="flex min-w-0 flex-wrap items-center gap-x-1 gap-y-1 text-xs text-muted-foreground"
            >
                <Repeat class="size-3 shrink-0" aria-hidden="true" />
                <span>Generated from:</span>
                <Link
                    v-if="task.generated_from.can_manage && task.generated_from.project_id"
                    :href="`/admin/projects/${task.generated_from.project_id}`"
                    class="rounded-sm font-medium text-foreground underline-offset-4 hover:underline focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    {{ task.generated_from.template }}
                </Link>
                <span v-else class="font-medium text-foreground">{{ task.generated_from.template }}</span>
                <span v-if="task.generated_from.period_label">
                    · period {{ task.generated_from.period_label }}
                </span>
            </p>

            <TaskStatusActions
                :task="task"
                :surface="surface"
                :reviewers="reviewers"
                @settled="emit('settled')"
                @hand-off="handOff"
            />
        </div>

        <div
            :class="
                cn(
                    'grid min-w-0 gap-6',
                    variant === 'page' && 'xl:grid-cols-3',
                )
            "
        >
            <div :class="cn('flex min-w-0 flex-col gap-6', variant === 'page' && 'xl:col-span-2')">
                <TaskFieldsPanel
                    :task="task"
                    :surface="surface"
                    :priorities="priorities"
                    :tags="tags"
                    :projects="projects"
                    @settled="emit('settled')"
                />
                <TaskSummaryPanel :task="task" :surface="surface" @settled="emit('settled')" />
                <TaskChecklistPanel :task="task" :surface="surface" @settled="emit('settled')" />

                <!--
                    `canUpload` is the task's OWN server-resolved permission — the ability
                    `FileService::guardMayAttach()` asks for — never a role and never a surface.
                    `changed` is how the drawer learns to re-read: it holds a payload it fetched
                    itself, and `attachment_count` on it goes stale the moment a file lands.
                -->
                <FilePanel
                    :routes="attachmentRoutes"
                    :can-upload="task.permissions.can_update"
                    title="Attachments"
                    description="Briefs, screenshots and anything else this task is about."
                    empty-description="Anything attached to this task shows up here."
                    @changed="emit('settled')"
                />

                <TaskDiscussionPanel
                    :task="task"
                    :surface="surface"
                    :discussion="discussion"
                    @settled="emit('settled')"
                />

                <TaskActivityPanel :activity="activity" />
            </div>

            <div class="flex min-w-0 flex-col gap-6">
                <TaskPeoplePanel
                    ref="people"
                    :task="task"
                    :surface="surface"
                    :employees="employees"
                    @settled="emit('settled')"
                />
                <TaskLinksPanel :task="task" :surface="surface" @settled="emit('settled')" />
                <TaskDependenciesPanel
                    :task="task"
                    :surface="surface"
                    :siblings="siblings"
                    @settled="emit('settled')"
                />

                <Card class="min-w-0 gap-4">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Time</CardTitle>
                        <CardDescription>Tracked against this task so far.</CardDescription>
                    </CardHeader>
                    <CardContent class="flex min-w-0 flex-col gap-1">
                        <p v-if="task.tracked_seconds > 0" class="text-2xl font-semibold tabular-nums">
                            {{ formatTracked(task.tracked_seconds) }}
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            Nothing tracked yet — the timer arrives in Phase 3.
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Created {{ formatDateTime(task.created_at) }}
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="task.permissions.can_archive || task.permissions.can_delete" class="min-w-0 gap-4">
                    <CardHeader>
                        <CardTitle class="text-sm font-medium">Manage</CardTitle>
                        <CardDescription>
                            Archiving takes the task out of the active views and makes it read-only.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="task.permissions.can_archive && (!task.is_archived || mayUnarchive)"
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="archiving"
                            @click="toggleArchive"
                        >
                            <component :is="task.is_archived ? ArchiveRestore : Archive" aria-hidden="true" />
                            {{ task.is_archived ? 'Unarchive' : 'Archive' }}
                        </Button>
                        <Button
                            v-if="task.permissions.can_delete"
                            type="button"
                            size="sm"
                            variant="destructive"
                            @click="deleteOpen = true"
                        >
                            <Trash2 aria-hidden="true" />
                            Delete
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>

    <Dialog v-model:open="deleteOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete “{{ task.title }}”?</DialogTitle>
                <DialogDescription>
                    It disappears from every list. The row is kept — a delete here is soft, so the
                    task’s history and its audit trail survive — but nothing in the app brings it back.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="deleting" @click="deleteOpen = false">
                    Keep it
                </Button>
                <Button type="button" variant="destructive" :disabled="deleting" @click="confirmDelete">
                    {{ deleting ? 'Deleting…' : 'Delete task' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
