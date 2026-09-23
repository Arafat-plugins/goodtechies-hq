<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, History, ShieldAlert, TriangleAlert } from '@lucide/vue';
import { ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { RecurringLogEntry, RecurringRoutes, RecurringTemplate } from '@/Components/Recurring/recurring';
import { formatDateTime, loadLog, logTone } from '@/Components/Recurring/recurring';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Skeleton } from '@/Components/ui/skeleton';

/**
 * The generation log: every attempt this template has made, newest first.
 *
 * ## It is written as sentences because it is read once a year
 *
 * The audience opens this exactly when something looks wrong — a month with no task, a task
 * that turned up twice, a retainer that has quietly stopped. So a row is not an enum and two
 * nullable ids: `RecurringGenerationLogResource` sends `sentence`, which is the engine's own
 * message wherever it wrote one ("An instance for October 2026 already exists (task #41).").
 * The words in the log and the words in the flash that appeared when somebody pressed Generate
 * are therefore the same words, which is what makes the two comparable a month later.
 *
 * ## Warnings are marked twice
 *
 * A badge with its label, and an icon. Not a colour: four of the eight status dots fail 3:1 in
 * light mode with the label removed (DESIGN.md §2.2, §5.6), and "this row is the problem" is
 * exactly the meaning that must survive being unable to see the difference.
 *
 * It is fetched when the dialog opens rather than riding on the template payload: three
 * templates on a screen must not drag a year of history each to print one row.
 */

const props = defineProps<{
    open: boolean;
    routes: RecurringRoutes;
    template: RecurringTemplate | null;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const entries = ref<RecurringLogEntry[]>([]);
const loading = ref(false);
const failure = ref<'forbidden' | 'failed' | null>(null);
/** Bumped per load, so an answer for a dialog that has since closed cannot land in it. */
const token = ref(0);

async function load(): Promise<void> {
    const template = props.template;

    if (template === null) {
        return;
    }

    const mine = ++token.value;
    loading.value = true;

    const result = await loadLog(props.routes, template.id);

    if (mine !== token.value) {
        return;
    }

    if (result.ok) {
        entries.value = result.payload.entries;
        failure.value = null;
    } else {
        entries.value = [];
        failure.value = result.reason;
    }

    loading.value = false;
}

watch(
    () => [props.open, props.template?.id] as const,
    ([open]) => {
        if (open) {
            void load();
        }
    },
    { immediate: true },
);

/** Each row's icon, so the warning is not carried by the badge's tint alone. */
function iconFor(entry: RecurringLogEntry) {
    return entry.is_warning ? TriangleAlert : CheckCircle2;
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[90dvh] max-w-2xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Generation log</DialogTitle>
                <DialogDescription>
                    Every attempt at <span class="font-medium">{{ template?.title_template }}</span
                    >, newest first. One row per run, whatever the run did.
                </DialogDescription>
            </DialogHeader>

            <div v-if="loading" class="flex flex-col gap-3">
                <Skeleton v-for="line in 3" :key="line" class="h-12 w-full" />
            </div>

            <EmptyState
                v-else-if="failure !== null"
                :icon="ShieldAlert"
                variant="error"
                :title="failure === 'forbidden' ? 'Not yours to read' : 'The log did not load'"
                :description="
                    failure === 'forbidden'
                        ? 'This template belongs to a project you cannot see.'
                        : 'Something went wrong fetching it. Try opening it again.'
                "
            />

            <EmptyState
                v-else-if="entries.length === 0"
                :icon="History"
                title="Nothing has run yet"
                description="The scheduler writes a row here every time it tries this template — including the times it decides not to. Generate now fills the first one."
            />

            <ol v-else class="flex min-w-0 flex-col gap-2">
                <li
                    v-for="entry in entries"
                    :key="entry.id"
                    class="flex min-w-0 gap-3 rounded-lg border p-3"
                >
                    <component
                        :is="iconFor(entry)"
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <StatusBadge
                                size="sm"
                                :status="logTone(entry)"
                                :label="entry.outcome_label ?? 'Attempted'"
                            />
                            <span class="text-xs text-muted-foreground">
                                {{ entry.period_label ?? entry.period }} ·
                                {{ formatDateTime(entry.created_at) }}
                            </span>
                        </div>

                        <!-- The server's sentence, printed unchanged. -->
                        <p class="text-sm break-words">{{ entry.sentence }}</p>

                        <div class="flex min-w-0 flex-wrap gap-3">
                            <Link
                                v-if="entry.task"
                                :href="`/admin/tasks/${entry.task.id}`"
                                class="rounded-sm text-xs font-medium underline-offset-4 hover:underline focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                Open the task it made
                            </Link>
                            <!--
                                The other half of the warning: last period's instance was still
                                open when this one was generated. It is a flag, never a refusal.
                            -->
                            <Link
                                v-if="entry.previous_open_task"
                                :href="`/admin/tasks/${entry.previous_open_task.id}`"
                                class="rounded-sm text-xs font-medium underline-offset-4 hover:underline focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                Last period is still open
                            </Link>
                        </div>
                    </div>
                </li>
            </ol>

            <DialogFooter>
                <Button type="button" variant="outline" @click="emit('update:open', false)">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
