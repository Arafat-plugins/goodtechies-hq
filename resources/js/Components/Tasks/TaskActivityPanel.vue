<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import type { TaskActivityEntry } from '@/Components/Tasks/taskDetail';
import { formatDateTime } from '@/Components/Tasks/taskDetail';
import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

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

/** Brief 025: collapsed by default; the heading row "Activity (N)" opens and closes it. */
const expanded = ref(false);
const regionId = `task-activity-${useId()}`;
</script>

<template>
    <Card class="min-w-0 gap-3" data-task-activity>
        <CardHeader>
            <h3 class="text-sm font-semibold">
                <button
                    type="button"
                    class="-mx-1 flex items-center gap-1 rounded-sm px-1 outline-none hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring"
                    :aria-expanded="expanded"
                    :aria-controls="regionId"
                    data-task-activity-toggle
                    @click="expanded = !expanded"
                >
                    Activity ({{ entries.length }})
                    <ChevronDown
                        :class="cn('size-4 text-muted-foreground transition-transform', expanded && 'rotate-180')"
                        aria-hidden="true"
                    />
                </button>
            </h3>
        </CardHeader>

        <CardContent v-show="expanded" :id="regionId">
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
