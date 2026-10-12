<script setup lang="ts">
/**
 * Polish 057: a project's open tasks as cards — assignee faces (or "Unassigned"), due date,
 * status, time on the right; each card opens the task. Shared by Admin → Projects?client=…
 * (ClientServiceBoard) and the website cards on a client's page. Taken out of ClientServiceBoard
 * unchanged (polish 041/042 layout).
 */
import { Link } from '@inertiajs/vue3';
import { CalendarDays, Clock } from '@lucide/vue';
import type { ServiceBoardTask } from '@/Components/Projects/ClientServiceBoard.vue';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { personTone } from '@/Components/Messages/people';
import { formatDate } from '@/Components/Tasks/taskDetail';
import { formatDuration } from '@/Components/Timer/timer';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

defineProps<{
    tasks: ServiceBoardTask[];
    projectName: string;
    /** The list's id, for the toggle's aria-controls. */
    listId: string;
}>();

const today = new Date().toISOString().slice(0, 10);

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function time(seconds: number): string {
    return seconds > 0 ? formatDuration(seconds) : '0m';
}
</script>

<template>
    <p v-if="!tasks.length" :id="listId" class="text-xs text-muted-foreground">No open tasks</p>
    <TooltipProvider v-else>
        <ul :id="listId" class="flex min-w-0 flex-col gap-2" :aria-label="`Open tasks in ${projectName}`">
            <li v-for="task in tasks" :key="task.id">
                <Link
                    :href="`/admin/tasks/${task.id}`"
                    class="flex min-w-0 flex-col gap-1.5 rounded-md border bg-background px-2.5 py-2.5 text-sm hover:border-ring focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <span class="leading-snug break-words">{{ task.title }}</span>
                    <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span v-if="task.assignees.length" class="flex shrink-0 items-center -space-x-1">
                            <Tooltip v-for="person in task.assignees.slice(0, 3)" :key="person.id">
                                <TooltipTrigger as-child>
                                    <Avatar role="img" :aria-label="person.name" class="size-5 ring-2 ring-background">
                                        <AvatarFallback :class="cn('text-xs', personTone(person.id).avatar)" aria-hidden="true">
                                            {{ initials(person.name) }}
                                        </AvatarFallback>
                                    </Avatar>
                                </TooltipTrigger>
                                <TooltipContent>{{ person.name }}</TooltipContent>
                            </Tooltip>
                            <Tooltip v-if="task.assignees.length > 3">
                                <TooltipTrigger as-child>
                                    <span
                                        role="img"
                                        :aria-label="`${task.assignees.length - 3} more: ${task.assignees.slice(3).map((p) => p.name).join(', ')}`"
                                        class="inline-flex size-5 items-center justify-center rounded-full bg-muted text-xs text-muted-foreground ring-2 ring-background"
                                    >
                                        <span aria-hidden="true">+{{ task.assignees.length - 3 }}</span>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>{{ task.assignees.slice(3).map((p) => p.name).join(', ') }}</TooltipContent>
                            </Tooltip>
                        </span>
                        <span v-else class="text-xs text-muted-foreground">Unassigned</span>
                        <span
                            v-if="task.due_date"
                            :class="cn('inline-flex items-center gap-1 tabular-nums', task.due_date < today && 'font-medium text-destructive')"
                        >
                            <CalendarDays class="size-3" aria-hidden="true" />
                            {{ formatDate(task.due_date) }}
                        </span>
                        <StatusBadge v-if="task.tone" :status="task.tone as StatusKey" :label="task.status_label ?? undefined" />
                        <span v-else-if="task.status_label">{{ task.status_label }}</span>
                        <span class="ml-auto inline-flex items-center gap-1 tabular-nums">
                            <Clock class="size-3" aria-hidden="true" />
                            {{ time(task.tracked_seconds) }}
                        </span>
                    </span>
                </Link>
            </li>
        </ul>
    </TooltipProvider>
</template>
