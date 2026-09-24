<script setup lang="ts">
import { computed } from 'vue';
import MessageThread from '@/Components/Messages/MessageThread.vue';
import { taskThreadRoutes } from '@/Components/Messages/messages';
import type { TaskDetail, TaskDiscussion, TaskSurface } from '@/Components/Tasks/taskDetail';
import { Card, CardContent } from '@/Components/ui/card';

/**
 * The task's discussion: the thread, and a composer that can carry a file and name people.
 *
 * ## It is the messaging thread, in a card
 *
 * Phase 2 built this panel's thread, unread line and composer by hand, because there was one
 * kind of conversation and nowhere else to put them. Phase 6 has four more kinds and the same
 * three things on every one of them, so the body moved to `Components/Messages/MessageThread`
 * and this is what is left: the card chrome, the endpoints for a task's discussion, and the
 * `settled` emit the detail screen listens to.
 *
 * That is also **how the task Discussion tab gained @mentions**: not by growing a second
 * picker, but by being the same thread the Messages page is. A mention here notifies exactly as
 * one in a project channel does, and somebody who is both named in a comment and an assignee of
 * its task gets one notification, not two — see `App\Events\TaskCommented`.
 *
 * `discussion.can_post` is still the only thing that decides whether a composer exists:
 * `ConversationPolicy::post`, which for a task delegates to `TaskPolicy::view`. Not a role, not
 * `is_mine`, and never a `conversation_members` row — membership is read state and grants
 * nothing, so a row left behind on a reassigned task buys its holder nothing.
 */

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
    discussion: TaskDiscussion;
}>();

/** A message landed, so the task's activity trail has a new line on it. */
const emit = defineEmits<{ settled: [] }>();

const routes = computed(() => taskThreadRoutes(props.surface, props.task.id));
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardContent class="min-w-0">
            <MessageThread
                :thread="discussion"
                :routes="routes"
                heading="Discussion"
                description="Questions and answers about this task, oldest first."
                @settled="emit('settled')"
            />
        </CardContent>
    </Card>
</template>
