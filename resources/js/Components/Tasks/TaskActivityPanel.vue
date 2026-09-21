<script setup lang="ts">
import { computed } from 'vue';
import type { TaskActivityEntry } from '@/Components/Tasks/taskDetail';
import { formatDateTime } from '@/Components/Tasks/taskDetail';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

/**
 * The task's activity trail, newest first, as `ActivityLogger` wrote it.
 *
 * The lines are the server's sentences, including the one a reopening writes — "Reopened from
 * Completed to In progress (completed … by … — that completion is kept)". Nothing here
 * reformats them: an activity line that the screen rewrites is a line that stops matching the
 * audit log beside it.
 */

const props = defineProps<{
    activity: TaskActivityEntry[];
}>();

const entries = computed(() => props.activity);
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Activity</CardTitle>
            <CardDescription>What has happened to this task, newest first.</CardDescription>
        </CardHeader>

        <CardContent>
            <p v-if="entries.length === 0" class="text-sm text-muted-foreground">Nothing recorded yet.</p>

            <ol v-else class="flex min-w-0 flex-col gap-4">
                <li
                    v-for="(entry, index) in entries"
                    :key="`${entry.at}-${index}`"
                    class="flex min-w-0 gap-3"
                >
                    <!-- A rail rather than a dot per row: one timeline, not a column of bullets. -->
                    <div class="flex shrink-0 flex-col items-center" aria-hidden="true">
                        <span class="mt-1.5 size-2 rounded-full bg-border" />
                        <span v-if="index < entries.length - 1" class="w-px flex-1 bg-border" />
                    </div>
                    <div class="flex min-w-0 flex-col gap-0.5 pb-1">
                        <p class="min-w-0 text-sm break-words">{{ entry.description }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ entry.actor ?? 'System' }} · {{ formatDateTime(entry.at) }}
                        </p>
                    </div>
                </li>
            </ol>
        </CardContent>
    </Card>
</template>
