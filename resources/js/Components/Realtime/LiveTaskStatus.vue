<script setup lang="ts">
import { computed, watch } from 'vue';
import LiveIndicator from '@/Components/Realtime/LiveIndicator.vue';
import { TASK_DETAIL_POLL_MS, taskChannel } from '@/Components/Realtime/live';
import { useLiveProps } from '@/Components/Realtime/reload';

/**
 * "This task is current" — the live half of a task detail page (POLISH-BACKLOG §A.3).
 *
 * ## What changed here, and why it is the whole point of the line in §A.3
 *
 * §A.3's task-detail row said *"status changes already broadcast and should paint"*, and until
 * now they did not. This component subscribed to `task.{id}`, read `status_label` **out of the
 * frame**, composed a sentence from it and offered a Reload button. Three things were wrong with
 * that and only one of them was visible:
 *
 *  1. It never painted. Somebody watching a task go to In review saw a notice and a button, not
 *     the status. The client's words were *"you applied after reload"*, and a Reload button is a
 *     reload.
 *  2. It painted from a payload. The frame is now the task's id and nothing else
 *     (`App\Events\Concerns\BroadcastsTaskStatus`), so a screen that reads `status_label` off it
 *     reads `undefined` — but the deeper problem is that a screen painting out of a frame is a
 *     screen whose correctness depends on the frame, and the policy is not in the frame.
 *  3. It did nothing at all on a polling build, which is the build the client runs. There was no
 *     interval: `useRealtimeChannel()` is a no-op without a socket, so on their machine this
 *     component was a comment.
 *
 * So it re-reads instead. `task` and `activity` are asked for by name, the status badge and the
 * available transitions repaint from what `TaskPolicy` and `TaskResource` built for this reader,
 * and the same code path runs on both builds — instantly on a socket, every fifteen seconds
 * without one.
 *
 * ## It cannot land under somebody's hands
 *
 * A partial reload preserves component state and scroll and never remounts the tree, so an
 * open editor keeps its draft (`TaskFieldsPanel` holds one) and focus does not move. The two
 * things it could still disturb are an open dialog and an open menu — the work-summary form, the
 * ⋯ menu, the tag picker — and `useLiveProps` refuses while either is open and delivers the
 * refresh on the next tick after it closes.
 *
 * `discussion` is deliberately NOT in the list: `MessageThread` keeps that current on its own
 * ten-second transport and holds local state against the prop, so re-reading it here would be a
 * second timer on one list.
 */

const props = defineProps<{
    taskId: number;
    /** The status the page is currently rendered with — what the indicator's sentence is about. */
    status: string | null;
}>();

/**
 * The subscription and the interval. `task.{id}`'s callback is `TaskPolicy::view`, the same
 * policy that rendered the page, so a reader who may not see this task cannot subscribe — and
 * a re-read they are not entitled to answers 404 anyway.
 */
const live = useLiveProps(['task', 'activity'], {
    channel: () => taskChannel(props.taskId),
    intervalMs: TASK_DETAIL_POLL_MS,
});

/** A fresh payload is a refresh that landed, whatever asked for it. */
watch(
    () => props.status,
    () => live.markFresh(),
);

const pending = computed(() => live.pending.value);
</script>

<template>
    <!--
        Last in the page's flow, like every live notice on this screen: it is one quiet line and
        it pushes nothing that precedes it, so no content moves under a cursor or a screen
        reader's position when the transport changes what it says.
    -->
    <div class="mt-4 flex justify-end">
        <LiveIndicator
            :transport="live.transport.value"
            :interval-ms="TASK_DETAIL_POLL_MS"
            :pending="pending"
            subject="changes other people make to this task"
        />
    </div>
</template>
