<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import LiveUpdateNotice from '@/Components/Realtime/LiveUpdateNotice.vue';
import { useRealtimeChannel } from '@/echo';

/**
 * "This task moved" — the live half of a task detail page (channel `task.{id}`, Part D §10).
 *
 * It is the `task.{id}` subscription and the sentence it produces; `LiveUpdateNotice` owns how
 * that sentence is drawn, announced and offered — one shape for every live notice in the
 * application, and the argument for that shape is in that file.
 *
 * ## It is a delivery mechanism, not a permission
 *
 * The channel's callback is `TaskPolicy::view` (`app/Broadcasting/TaskChannel.php`) — the same
 * policy that let this page render. A subscription this reader is not entitled to is refused
 * with 403 at `/broadcasting/auth`, and the payload carries only the status, which
 * `TaskResource` already sent them. Nothing becomes visible by arriving live.
 *
 * On a polling build this component subscribes to nothing, says nothing and costs nothing —
 * `useRealtimeChannel()` is a no-op there. The page is as correct as it ever was; it is simply
 * as current as the last time it was loaded, which is what a polling build means.
 */

const props = defineProps<{
    taskId: number;
    /** The status the page was rendered with, so an echo of it is not news. */
    status: string | null;
}>();

interface TaskStatusPayload {
    task_id: number;
    status: string | null;
    status_label: string | null;
}

/** The sentence, or empty while there is nothing to say. */
const message = ref('');

useRealtimeChannel(`task.${props.taskId}`, {
    'status.changed': (payload: never) => {
        const moved = payload as unknown as TaskStatusPayload;

        // A move to where we already are is somebody else's screen catching up, not news.
        if (moved.task_id !== props.taskId || moved.status === props.status) {
            return;
        }

        message.value = `This task moved to ${moved.status_label ?? 'another status'}.`;
    },
});

// A reload the reader asked for clears the notice; the fresh props then carry the new status.
watch(
    () => props.status,
    () => {
        message.value = '';
    },
);

function reload(): void {
    // `reload()` keeps the scroll position by default in Inertia 3; it is a re-fetch of the
    // page somebody is already on, not a navigation.
    router.reload();
}
</script>

<template>
    <LiveUpdateNotice :message="message" @reload="reload" />
</template>
