<script setup lang="ts">
import { CheckCircle2, History, Pencil } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import type { TaskDetail, TaskSurface } from '@/Components/Tasks/taskDetail';
import { focusField, formatDateTime, mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

/**
 * The work summary, and every completion this task has had.
 *
 * `first_completion` is the reason this panel exists. It is written once, when a task is
 * completed for the first time, and nothing ever overwrites it — so a task that was completed,
 * reopened and worked on again still carries who finished it the first time and what they
 * said. A reopening clears `completed_at` / `completed_by` (they would contradict a status of
 * In progress), which is exactly why the history cannot be read off those fields and gets a
 * panel of its own.
 */

const props = defineProps<{
    task: TaskDetail;
    surface: TaskSurface;
}>();

const emit = defineEmits<{ settled: [] }>();

const routes = computed(() => taskRoutes(props.surface, props.task.id));

const summary = computed(() => props.task.work_summary?.trim() ?? '');

/** The live completion, when there is one and it is not simply the first one again. */
const recompleted = computed(
    () =>
        props.task.completed_at !== null &&
        props.task.first_completion !== null &&
        props.task.completed_at !== props.task.first_completion.at,
);

/** Completed once, and not completed now: the task was reopened. */
const reopened = computed(() => props.task.first_completion !== null && props.task.completed_at === null);

/* ---------------------------------------------------------------- editing it */

const editing = ref(false);
const draft = ref('');
const saving = ref(false);
const field = ref<{ $el?: unknown } | null>(null);
const opener = ref<{ $el?: unknown } | null>(null);

function edit(): void {
    draft.value = summary.value;
    editing.value = true;
    void nextTick(() => focusField(field.value));
}

function cancel(): void {
    editing.value = false;
    void nextTick(() => focusField(opener.value));
}

/**
 * Written through the general update endpoint, which accepts `work_summary` from either
 * assignee. The authorship travels with it server-side: `TaskService` records who wrote it,
 * and that is what completion is checked against.
 */
function save(): void {
    if (saving.value) {
        return;
    }

    saving.value = true;

    mutateTask(
        'put',
        routes.value.update,
        { work_summary: draft.value.trim() === '' ? null : draft.value.trim() },
        {
            onAccepted: () => {
                editing.value = false;
                void nextTick(() => focusField(opener.value));
            },
            onSettled: () => emit('settled'),
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <Card class="min-w-0 gap-4">
        <CardHeader>
            <CardTitle class="text-sm font-medium">Work summary</CardTitle>
            <CardDescription>
                What was done. Submitting for review needs one, and completing the task needs the
                <template v-if="task.primary_assignee">primary assignee’s — {{ task.primary_assignee.name }}’s.</template>
                <template v-else>primary assignee’s.</template>
            </CardDescription>
        </CardHeader>

        <CardContent class="flex min-w-0 flex-col gap-4">
            <form v-if="editing" class="flex min-w-0 flex-col gap-2" novalidate @submit.prevent="save">
                <Label for="task-work-summary">Work summary</Label>
                <Textarea
                    id="task-work-summary"
                    ref="field"
                    v-model="draft"
                    rows="6"
                    :disabled="saving"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" size="sm" :disabled="saving">
                        {{ saving ? 'Saving…' : 'Save summary' }}
                    </Button>
                    <Button type="button" size="sm" variant="outline" :disabled="saving" @click="cancel">
                        Cancel
                    </Button>
                </div>
            </form>

            <template v-else>
                <p v-if="summary" class="min-w-0 text-sm whitespace-pre-line">{{ summary }}</p>
                <p v-else class="text-sm text-muted-foreground">Nothing written yet.</p>

                <p class="text-xs text-muted-foreground">
                    <template v-if="task.work_summary_by">
                        By {{ task.work_summary_by.name }} · {{ formatDateTime(task.work_summary_at) }}
                    </template>
                    <template v-else>Nobody has written one.</template>
                </p>

                <div v-if="task.permissions.can_update">
                    <Button ref="opener" type="button" size="sm" variant="outline" @click="edit">
                        <Pencil aria-hidden="true" />
                        {{ summary ? 'Edit summary' : 'Write a summary' }}
                    </Button>
                </div>
            </template>

            <!--
                The completion history. Present whenever the task has ever been completed —
                the whole point of `first_completed_*` is that a reopening cannot lose it.
            -->
            <div v-if="task.first_completion" class="flex min-w-0 flex-col gap-3 border-t pt-4">
                <p class="flex items-center gap-2 text-sm font-medium">
                    <History class="size-4 text-muted-foreground" aria-hidden="true" />
                    Completion history
                </p>

                <div class="flex min-w-0 flex-col gap-1">
                    <!--
                        `first_completed_by` is whoever MADE the move — the reviewer who
                        approved it — while the summary under it is the primary assignee's
                        work. Saying "by X" over Y's words would read as X having written
                        them, so the two are named for what they are.
                    -->
                    <p class="text-xs font-medium">
                        First completed {{ formatDateTime(task.first_completion.at) }}
                        <template v-if="task.first_completion.by">
                            · recorded by {{ task.first_completion.by.name }}
                        </template>
                    </p>
                    <p
                        v-if="task.first_completion.work_summary"
                        class="min-w-0 rounded-md border bg-muted p-3 text-sm whitespace-pre-line"
                    >
                        {{ task.first_completion.work_summary }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        No work summary was recorded with that completion.
                    </p>
                </div>

                <p v-if="reopened" class="text-xs text-muted-foreground">
                    Reopened since — the task is back in {{ task.status_label }}. That first completion
                    is kept for good.
                </p>

                <p v-else-if="recompleted" class="flex items-center gap-2 text-xs text-muted-foreground">
                    <CheckCircle2 class="size-4 shrink-0 text-status-done-fg" aria-hidden="true" />
                    Completed again {{ formatDateTime(task.completed_at) }}
                    <template v-if="task.completed_by">by {{ task.completed_by.name }}</template>
                </p>
            </div>
        </CardContent>
    </Card>
</template>
