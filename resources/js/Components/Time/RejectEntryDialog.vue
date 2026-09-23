<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { useId, watch } from 'vue';
import type { AdminTimeEntry } from '@/Components/Time/time';
import { adminTimeRoutes, shortDate } from '@/Components/Time/time';
import { formatDuration } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

/**
 * Turning an entry down, with the reason it was turned down.
 *
 * ## Rejecting is not deleting, and the dialog says so
 *
 * The row stays. The hours stay on it, on the employee's own Time page, with this sentence
 * beside them saying why they are not in any total. That is the whole difference between a
 * refusal and a deletion, and the person deciding should know which one they are doing before
 * they press the button — hence the line under the field rather than a toast afterwards.
 *
 * ## The reason is not a nicety
 *
 * `RejectTimeEntryRequest` requires it, so an empty one is refused by the endpoint and not only
 * by the `required` attribute here. It is stored on the row and shown to the person whose
 * afternoon it was: "no" with nothing attached is not something they can act on.
 */

const props = defineProps<{
    open: boolean;
    entry: AdminTimeEntry | null;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const reasonId = useId();

const form = useForm({ reason: '' });

watch(
    () => [props.open, props.entry?.id],
    () => {
        if (props.open) {
            form.clearErrors();
            form.reason = '';
        }
    },
    { immediate: true },
);

function submit(): void {
    if (!props.entry) {
        return;
    }

    form.post(adminTimeRoutes.reject(props.entry.id), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Turn down these hours</DialogTitle>
                <DialogDescription v-if="entry">
                    {{ formatDuration(entry.duration_seconds) }} on {{ entry.task?.name ?? 'a task' }},
                    {{ shortDate(entry.work_date) }} — {{ entry.employee.name }}.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label :for="reasonId">Reason</Label>
                    <Textarea
                        :id="reasonId"
                        v-model="form.reason"
                        rows="3"
                        placeholder="Why are these hours not being counted?"
                        required
                    />
                    <p v-if="form.errors.reason" class="text-xs text-destructive">{{ form.errors.reason }}</p>
                </div>

                <p class="flex items-start gap-2 text-xs text-muted-foreground">
                    <ShieldAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>
                        The entry is not deleted. It stays on {{ entry?.employee.name ?? 'their' }} record with this
                        reason beside it, and does not count toward any total. The decision is logged against your name.
                    </span>
                </p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" variant="destructive" :disabled="form.processing">Turn down</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
