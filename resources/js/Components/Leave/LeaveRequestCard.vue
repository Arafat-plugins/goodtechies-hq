<script setup lang="ts">
import { CalendarClock, Check, MessageSquareWarning, Pencil, X } from '@lucide/vue';
import type { LeaveRequestRow } from '@/Components/Leave/leave';
import { formatDays, formatWindow } from '@/Components/Leave/leave';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * One leave request, as a row.
 *
 * The same component on My Leave and in the Admin queue, because it is the same record read
 * from two ends: the queue shows who asked (the server omits `employee` on the reader's own
 * rows, so My Leave does not print the reader's own name forty times) and offers the three
 * verbs; My Leave shows the decision and offers the one move the employee has.
 *
 * ## The status is a word before it is a colour
 *
 * `StatusBadge` always prints `status_label`, and the tone comes from the server. DESIGN.md
 * §5.6 makes that a rule and §1.4 is the measurement behind it: two of the eight status tones
 * are ΔE 0.16 apart under deuteranopia, so a bare dot is not a status. Pending, Approved,
 * Rejected and Correction requested are four words here and four words everywhere.
 *
 * ## Nothing here decides what may be done
 *
 * `permissions.can_decide` and `.can_resubmit` arrive resolved by `LeaveRequestPolicy` per
 * record, and every endpoint checks again (decisions 2-28, 2-31). An Admin reading their own
 * request in the queue is sent `can_decide: false` — nobody rules on their own — so the buttons
 * are not drawn rather than drawn and refused.
 */

const props = defineProps<{
    request: LeaveRequestRow;
    /** Draw the three approver verbs. The row still checks its own `can_decide`. */
    showDecisions?: boolean;
}>();

const emit = defineEmits<{
    approve: [request: LeaveRequestRow];
    reject: [request: LeaveRequestRow];
    correction: [request: LeaveRequestRow];
    amend: [request: LeaveRequestRow];
}>();
</script>

<template>
    <article
        :class="
            cn(
                'flex min-w-0 flex-col gap-3 rounded-md border bg-card p-4',
                request.status === 'correction_requested' && 'border-status-changes-border',
            )
        "
    >
        <div class="flex min-w-0 flex-wrap items-start justify-between gap-2">
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-semibold">
                        <template v-if="request.employee">{{ request.employee.name }} — </template>
                        {{ request.type?.name ?? 'Leave' }}
                    </h3>
                    <StatusBadge :status="request.tone" :label="request.status_label" size="sm" />
                </div>

                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <CalendarClock class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="tabular-nums">{{ formatWindow(request.start_date, request.end_date) }}</span>
                    <span aria-hidden="true">·</span>
                    <span class="tabular-nums">{{ formatDays(request.days) }}</span>
                    <!--
                        Said out loud, because it is what Phase 9's payroll will read and the
                        person whose pay it is should never meet it first on a payslip.
                    -->
                    <template v-if="request.unpaid_days > 0">
                        <span aria-hidden="true">·</span>
                        <span class="font-medium text-foreground tabular-nums">
                            {{ formatDays(request.unpaid_days) }} unpaid
                        </span>
                    </template>
                </p>
            </div>
        </div>

        <p class="text-sm break-words whitespace-pre-line">{{ request.reason }}</p>

        <!--
            The decision, in the words the approver typed. A refusal with nothing attached is
            not something the person who asked can act on (the same rule decision 4-18 keeps for
            a refused time entry).
        -->
        <p
            v-if="request.decision_note"
            class="rounded-md border bg-muted/40 p-3 text-xs break-words whitespace-pre-line text-muted-foreground"
        >
            <span class="font-medium text-foreground">{{ request.approver?.name ?? 'Approver' }}:</span>
            {{ request.decision_note }}
        </p>

        <div v-if="showDecisions && request.permissions.can_decide" class="flex flex-wrap items-center gap-2">
            <Button size="sm" @click="emit('approve', request)">
                <Check class="size-4" aria-hidden="true" />
                Approve
            </Button>
            <Button size="sm" variant="outline" @click="emit('correction', request)">
                <MessageSquareWarning class="size-4" aria-hidden="true" />
                Request correction
            </Button>
            <Button size="sm" variant="destructive" @click="emit('reject', request)">
                <X class="size-4" aria-hidden="true" />
                Reject
            </Button>
        </div>

        <div v-if="request.permissions.can_resubmit" class="flex flex-wrap items-center gap-2">
            <Button size="sm" variant="outline" @click="emit('amend', request)">
                <Pencil class="size-4" aria-hidden="true" />
                Amend and send back
            </Button>
        </div>
    </article>
</template>
