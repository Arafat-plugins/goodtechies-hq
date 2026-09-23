<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, X } from '@lucide/vue';
import { ref } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { AdminTimeEntry } from '@/Components/Time/time';
import { adminTimeRoutes, clockTime, shortDate } from '@/Components/Time/time';
import { formatDuration } from '@/Components/Timer/timer';
import { Button } from '@/Components/ui/button';

/**
 * One entry in front of an Admin, with everything the decision needs and nothing else.
 *
 * The audience is one person clearing a short queue once or twice a week, so the row answers
 * four questions in one glance, in this order: **who, when, how long, and why it is waiting.**
 * Name and date lead because they are how the Admin recognises the afternoon; the duration is
 * the number being ruled on; `waiting_because` is the server's sentences and the reason the row
 * is here at all.
 *
 * ## Every state is a word
 *
 * Waiting, turned down, flagged and added-by-hand all print their label. Four of the eight
 * status fills fail 3:1 without their text and two are ΔE 0.16 apart under deuteranopia
 * (DESIGN.md §1.4, §2.2), so a tint is decoration here and the word is the state.
 *
 * ## The buttons are the server's answer
 *
 * `entry.permissions.can_decide` is `TimeEntryPolicy::approve` resolved per record. Nothing here
 * infers it from a role or from `entry_type` (decisions 2-28, 2-31), and an entry this viewer
 * may not rule on simply has no controls rather than controls the endpoint would refuse.
 *
 * `showApprove` is the caller's, not the policy's: the already-counted flagged list offers only
 * Reject, because there is nothing there to approve — only hours to take back out.
 */

const props = withDefaults(
    defineProps<{
        entry: AdminTimeEntry;
        /** False on the flagged list, where the entry already counts. */
        showApprove?: boolean;
    }>(),
    { showApprove: true },
);

const emit = defineEmits<{ reject: [entry: AdminTimeEntry] }>();

const approving = ref(false);

function approve(): void {
    approving.value = true;

    router.post(
        adminTimeRoutes.approve(props.entry.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                approving.value = false;
            },
        },
    );
}
</script>

<template>
    <li class="flex flex-col gap-3 rounded-md border bg-card p-4">
        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 flex-col gap-1">
                <!-- Who and when: how the Admin recognises the afternoon being ruled on. -->
                <p class="text-sm font-medium">
                    {{ entry.employee.name }}
                    <span class="text-muted-foreground">· {{ shortDate(entry.work_date) }}</span>
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ entry.task?.name ?? 'No task' }}
                    <template v-if="entry.project"> · {{ entry.project.name }}</template>
                </p>
                <p class="text-xs tabular-nums text-muted-foreground">
                    {{ clockTime(entry.started_at) }} – {{ clockTime(entry.ended_at) }}
                </p>
            </div>

            <div class="flex shrink-0 flex-col items-start gap-2 sm:items-end">
                <!-- How long. The number the decision is about, so it is the biggest thing here. -->
                <p class="text-lg font-semibold tabular-nums">{{ formatDuration(entry.duration_seconds) }}</p>

                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <StatusBadge
                        v-if="entry.approval_label"
                        :status="entry.approval === 'rejected' ? 'cancelled' : 'waiting'"
                        :label="entry.approval_label"
                        size="sm"
                    />
                    <StatusBadge v-else-if="entry.counts" status="done" label="Counted" size="sm" />
                </div>
            </div>
        </div>

        <!--
            Why it is here, in the server's sentences. A flag brings the watchdog's own wording
            with its threshold and its moment (decision 4-6) — an Admin who cannot read what the
            safeguard actually found has no basis on which to approve or refuse.
        -->
        <ul v-if="entry.waiting_because.length" class="flex flex-col gap-2">
            <li
                v-for="why in entry.waiting_because"
                :key="why.key"
                class="rounded-md px-3 py-2 text-xs"
                :class="
                    why.key === 'flagged'
                        ? 'bg-status-review-bg text-status-review-fg'
                        : 'bg-muted text-muted-foreground'
                "
            >
                <span class="font-medium">{{ why.label }}</span>
                <template v-if="why.detail">: {{ why.detail }}</template>
            </li>
        </ul>

        <!-- A refusal already made: the sentence stays with the row, and so do the hours. -->
        <p
            v-if="entry.approval === 'rejected'"
            class="rounded-md bg-status-cancelled-bg px-3 py-2 text-xs text-status-cancelled-fg"
        >
            <span class="font-medium">Turned down<template v-if="entry.rejected_by"> by {{ entry.rejected_by }}</template>:</span>
            {{ entry.rejection_reason ?? 'No reason was given.' }}
        </p>

        <p v-else-if="entry.approval === 'counted' && entry.approved_by" class="text-xs text-muted-foreground">
            Approved by {{ entry.approved_by }}. These hours count.
        </p>

        <div v-if="entry.permissions.can_decide" class="flex flex-wrap gap-2">
            <Button v-if="showApprove" size="sm" :disabled="approving" @click="approve">
                <Check aria-hidden="true" />
                {{ entry.approval === 'rejected' ? 'Approve after all' : 'Approve' }}
            </Button>
            <Button v-if="entry.approval !== 'rejected'" size="sm" variant="outline" @click="emit('reject', entry)">
                <X aria-hidden="true" />
                Turn down
            </Button>
        </div>
    </li>
</template>
