<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { Errors } from '@inertiajs/core';
import { computed, ref, watch } from 'vue';
import type { TimeableTask, TimeEntry } from '@/Components/Timer/timer';
import { timerRoutes } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';

/**
 * Adding a stretch of time by hand, and correcting one that is already recorded.
 *
 * One dialog for both, because they are the same four fields and the same rule: **a reason is
 * required**. A manual entry is a claim rather than a measurement, so the one thing it must
 * carry is why it exists; an edit changes a figure that was already recorded, so it must carry
 * why even more. The server requires it either way, and writes an edit to `audit_logs` with the
 * old and the new values.
 *
 * The dialog says out loud what will happen to the time, because
 * `settings.manual_time_requires_approval` decides whether it counts today or waits for an
 * Admin, and an employee who is not told that reports it as lost hours.
 *
 * Focus trap, Esc and focus restore are reka's dialog — this adds none of its own.
 */

const props = defineProps<{
    open: boolean;
    /** Null to add a new entry; an entry to correct it. */
    entry?: TimeEntry | null;
    tasks: TimeableTask[];
    /** `settings.manual_time_requires_approval`, from the server. */
    requiresApproval: boolean;
}>();

const emit = defineEmits<{ 'update:open': [boolean]; saved: [] }>();

const editing = computed(() => props.entry != null);

const taskId = ref<number | null>(null);
const startedAt = ref('');
const endedAt = ref('');
const reason = ref('');
const saving = ref(false);
const errors = ref<Errors>({});

/** `<input type="datetime-local">` wants `YYYY-MM-DDTHH:mm` in local time. */
function toLocalInput(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errors.value = {};

        if (props.entry) {
            taskId.value = props.entry.task?.id ?? null;
            startedAt.value = toLocalInput(props.entry.started_at);
            endedAt.value = toLocalInput(props.entry.ended_at);
            reason.value = props.entry.reason ?? '';

            return;
        }

        taskId.value = props.tasks[0]?.id ?? null;

        // Default to an hour ending now, which is the shape of the case this exists for:
        // "I worked on this and forgot to start the timer."
        const now = new Date();
        const hourAgo = new Date(now.getTime() - 3600_000);

        startedAt.value = toLocalInput(hourAgo.toISOString());
        endedAt.value = toLocalInput(now.toISOString());
        reason.value = '';
    },
);

function save(): void {
    if (saving.value) {
        return;
    }

    saving.value = true;
    errors.value = {};

    const payload = {
        task_id: taskId.value,
        started_at: startedAt.value,
        ended_at: endedAt.value,
        reason: reason.value,
    };

    const done = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            emit('update:open', false);
            emit('saved');
        },
        onError: (received: Errors) => {
            errors.value = received;
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (props.entry) {
        router.put(timerRoutes.entry(props.entry.id), payload, done);

        return;
    }

    router.post(timerRoutes.entries, payload, done);
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ editing ? 'Correct this entry' : 'Add time by hand' }}</DialogTitle>
                <DialogDescription>
                    <template v-if="editing">
                        Say what the hours should be and why they are changing. The change is
                        recorded with the old and the new values.
                    </template>
                    <template v-else>
                        For work the timer missed — a session you forgot to start, or one that
                        happened away from the laptop.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4">
                <div v-if="!editing" class="flex flex-col gap-1.5">
                    <Label for="entry-task">Task</Label>
                    <NativeSelect id="entry-task" v-model="taskId">
                        <NativeSelectOption v-for="task in tasks" :key="task.id" :value="task.id">
                            {{ task.title }}{{ task.project ? ` — ${task.project}` : '' }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <p v-if="errors.task_id" class="text-sm text-destructive">{{ errors.task_id }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <Label for="entry-start">Started</Label>
                        <Input id="entry-start" v-model="startedAt" type="datetime-local" />
                        <p v-if="errors.started_at" class="text-sm text-destructive">{{ errors.started_at }}</p>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <Label for="entry-end">Finished</Label>
                        <Input id="entry-end" v-model="endedAt" type="datetime-local" />
                        <p v-if="errors.ended_at" class="text-sm text-destructive">{{ errors.ended_at }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="entry-reason">Reason</Label>
                    <Textarea
                        id="entry-reason"
                        v-model="reason"
                        rows="3"
                        placeholder="Forgot to start the timer this morning."
                    />
                    <p v-if="errors.reason" class="text-sm text-destructive">{{ errors.reason }}</p>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="requiresApproval">
                            Time added by hand waits for an Admin to sign it off before it counts
                            toward the day. It is shown on this page in the meantime.
                        </template>
                        <template v-else>
                            This counts toward the day as soon as it is saved.
                        </template>
                    </p>
                </div>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="saving" @click="emit('update:open', false)">
                    Cancel
                </Button>
                <Button type="button" :disabled="saving" @click="save">
                    {{ saving ? 'Saving…' : editing ? 'Save the correction' : 'Add the time' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
