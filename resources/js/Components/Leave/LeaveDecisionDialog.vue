<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { computed, useId, watch } from 'vue';
import type { LeaveRequestRow } from '@/Components/Leave/leave';
import { formatDays, formatWindow, leaveRoutes } from '@/Components/Leave/leave';
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

/** Which of Part D §9's three verbs this dialog is asking about. */
export type LeaveDecision = 'approve' | 'reject' | 'correction';

/**
 * Ruling on a leave request: approve, reject, or send it back with a question.
 *
 * One dialog for all three, because it is one decision with three answers and they take the
 * same one field. What differs is whether the field is required and what the confirmation says
 * will happen — and both of those are stated here in the same place, so nobody can add a fourth
 * verb without deciding them.
 *
 * ## Approving needs no reason; the other two do
 *
 * "Yes" explains itself, and a required box on the common case is a box people fill with a full
 * stop. A refusal and a correction request both take something away from the person who asked —
 * the days, or the wait — and both are the sentence they will read on their own leave page, so
 * `DecideLeaveRequest` requires them and the endpoint refuses an empty one. The `required`
 * attribute here is the convenience; the rule is the server's.
 *
 * ## The dialog says what the button will do before it is pressed
 *
 * Approving is not a word changing colour: it takes the days off a balance, writes the
 * attendance rows and stores what payroll will read. That is a lot to happen behind a button
 * labelled Approve, so the panel under the field says it — the same reason
 * `RejectEntryDialog` says out loud that a refusal keeps the row.
 */

const props = defineProps<{
    open: boolean;
    decision: LeaveDecision;
    request: LeaveRequestRow | null;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const noteId = useId();

const form = useForm({ note: '' });

watch(
    () => [props.open, props.request?.id, props.decision],
    () => {
        if (props.open) {
            form.clearErrors();
            form.note = '';
        }
    },
    { immediate: true },
);

const copy = computed(() => {
    switch (props.decision) {
        case 'approve':
            return {
                title: 'Approve this leave',
                confirm: 'Approve',
                variant: 'default' as const,
                label: 'Note (optional)',
                placeholder: 'Anything you want them to see beside the approval.',
                required: false,
            };
        case 'reject':
            return {
                title: 'Turn this leave down',
                confirm: 'Reject',
                variant: 'destructive' as const,
                label: 'Reason',
                placeholder: 'Why these days cannot be taken.',
                required: true,
            };
        default:
            return {
                title: 'Send this back for a correction',
                confirm: 'Request correction',
                variant: 'default' as const,
                label: 'What needs changing',
                placeholder: 'What you need them to change before you can rule on it.',
                required: true,
            };
    }
});

/** What actually happens when the button is pressed, in the reader's own words. */
const consequence = computed(() => {
    const request = props.request;

    if (!request) {
        return '';
    }

    const name = request.employee?.name ?? 'They';

    switch (props.decision) {
        case 'approve': {
            const balance = request.type?.has_balance
                ? `${formatDays(request.days)} comes off their ${request.type.name} balance, `
                : 'Nothing comes off a balance — this type has none, ';
            const unpaid =
                request.unpaid_days > 0
                    ? ` ${formatDays(request.unpaid_days)} of it is recorded as unpaid for payroll.`
                    : '';

            return `${balance}those days are marked as Leave on their attendance, and the calendar shows them as away.${unpaid}`;
        }
        case 'reject':
            return `Nothing is spent and nothing is booked. The request stays on ${name.toLowerCase() === 'they' ? 'their' : `${name}'s`} leave page with your reason beside it.`;
        default:
            return `Nothing is spent and nothing is booked. ${name} can amend the request and send it back — it keeps its place.`;
    }
});

function submit(): void {
    if (!props.request) {
        return;
    }

    const url =
        props.decision === 'approve'
            ? leaveRoutes.approve(props.request.id)
            : props.decision === 'reject'
              ? leaveRoutes.reject(props.request.id)
              : leaveRoutes.correction(props.request.id);

    form.post(url, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ copy.title }}</DialogTitle>
                <DialogDescription v-if="request">
                    {{ request.employee?.name ?? 'This employee' }} — {{ request.type?.name ?? 'Leave' }},
                    {{ formatWindow(request.start_date, request.end_date) }}, {{ formatDays(request.days) }}.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label :for="noteId">{{ copy.label }}</Label>
                    <Textarea
                        :id="noteId"
                        v-model="form.note"
                        rows="3"
                        :placeholder="copy.placeholder"
                        :required="copy.required"
                    />
                    <p v-if="form.errors.note" class="text-xs text-destructive">{{ form.errors.note }}</p>
                </div>

                <p class="flex items-start gap-2 text-xs text-muted-foreground">
                    <ShieldAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{{ consequence }} The decision is logged against your name.</span>
                </p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :variant="copy.variant" :disabled="form.processing">
                        {{ copy.confirm }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
