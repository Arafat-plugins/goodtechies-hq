<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DetailDrawer from '@/Components/DetailDrawer.vue';
import SkeletonDetail from '@/Components/Skeletons/SkeletonDetail.vue';
import TaskDetailBody from '@/Components/Tasks/TaskDetailBody.vue';
import type { TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import type {
    TaskActivityEntry,
    TaskDetail,
    TaskSibling,
    TaskSurface,
} from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { toast } from '@/lib/toast';

/**
 * The Tasks List's slide-over: the same `TaskDetailBody` the detail page mounts, beside the
 * list instead of instead of it.
 *
 * **Why it fetches.** The list payload is not the detail payload. `TaskResource` puts the
 * checklist, the links and both dependency directions behind `whenLoaded`, and
 * `available_transitions` behind a `task_detail` request attribute — deliberately, because
 * resolving the transitions is a gate call per candidate status and doing that for two
 * hundred rows would be absurd. There is no JSON endpoint for one task, so the drawer asks
 * the detail route for its Inertia payload directly (the protocol's own `X-Inertia` GET) and
 * hands the props to the body unchanged. That is the same props object `Pages/…/Tasks/Show.vue`
 * receives, which is what makes the two mounts genuinely one screen.
 *
 * `DetailDrawer` owns Esc, the focus trap and returning focus to whatever opened it — the row.
 * Nothing here re-implements any of that.
 */

const props = defineProps<{
    open: boolean;
    taskId: number | null;
    surface: TaskSurface;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

interface DetailProps {
    task: TaskDetail;
    activity: TaskActivityEntry[];
    reviewers: { id: number; name: string | null }[];
    employees?: TaskNamedRef[];
    priorities?: TaskOption[];
    siblings?: TaskSibling[];
    /** The tag picker's options (both surfaces) and the move picker's (Admin only). */
    tags?: TaskTag[];
    projects?: TaskNamedRef[];
}

const page = usePage();
const detail = ref<DetailProps | null>(null);
const loading = ref(false);
/** Bumped per open so a slow answer for a closed drawer cannot land in it. */
const token = ref(0);

const href = computed(() => (props.taskId === null ? '' : `/${props.surface}/tasks/${props.taskId}`));

async function load(): Promise<void> {
    const id = props.taskId;

    if (id === null) {
        return;
    }

    const mine = ++token.value;
    loading.value = detail.value === null || detail.value.task.id !== id;

    try {
        const response = await fetch(`/${props.surface}/tasks/${id}`, {
            credentials: 'same-origin',
            headers: {
                'X-Inertia': 'true',
                'X-Inertia-Version': String(page.version ?? ''),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'text/html, application/xhtml+xml',
            },
        });

        if (mine !== token.value) {
            return;
        }

        // The build moved under us. A full visit is the honest answer — Inertia would do the
        // same thing for any other request.
        if (response.status === 409) {
            router.visit(href.value);

            return;
        }

        if (!response.ok) {
            // 404 is "you may not see this", said the way the backend says it everywhere.
            toast.error(
                response.status === 404
                    ? 'That task is not available.'
                    : 'That task could not be opened.',
            );
            emit('update:open', false);

            return;
        }

        detail.value = (await response.json()).props as DetailProps;
    } catch {
        if (mine === token.value) {
            toast.error('That task could not be opened.');
            emit('update:open', false);
        }
    } finally {
        if (mine === token.value) {
            loading.value = false;
        }
    }
}

watch(
    () => [props.open, props.taskId] as const,
    ([open, id]) => {
        if (!open || id === null) {
            return;
        }

        if (detail.value !== null && detail.value.task.id !== id) {
            detail.value = null;
        }

        void load();
    },
    { immediate: true },
);

/** A write inside the drawer refreshes the drawer; the list behind it refreshed itself. */
function refresh(): void {
    void load();
}

function close(): void {
    emit('update:open', false);
}
</script>

<template>
    <DetailDrawer
        :open="open"
        :title="detail?.task.title ?? 'Task'"
        :subtitle="detail?.task.project?.name ?? undefined"
        width="xl"
        :deep-link-id="taskId"
        @update:open="(value) => emit('update:open', value)"
    >
        <template #header-actions>
            <Button v-if="taskId !== null" as-child variant="outline" size="sm">
                <a :href="href">
                    <ExternalLink aria-hidden="true" />
                    <span class="sr-only sm:not-sr-only">Open</span>
                </a>
            </Button>
        </template>

        <SkeletonDetail v-if="loading || detail === null" />

        <TaskDetailBody
            v-else
            :key="detail.task.id"
            :task="detail.task"
            :activity="detail.activity"
            :surface="surface"
            :reviewers="detail.reviewers"
            :employees="detail.employees"
            :priorities="detail.priorities"
            :siblings="detail.siblings"
            :tags="detail.tags"
            :projects="detail.projects"
            variant="drawer"
            @settled="refresh"
            @removed="close"
        />
    </DetailDrawer>
</template>
