<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarOff, Inbox } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import ApplyLeaveForm from '@/Components/Leave/ApplyLeaveForm.vue';
import LeaveRequestCard from '@/Components/Leave/LeaveRequestCard.vue';
import type { LeaveBalanceRow, LeaveRequestRow, LeaveTypeOption } from '@/Components/Leave/leave';
import { formatBalance, formatWindow, LEAVE_OPEN_STATUSES, leaveRoutes } from '@/Components/Leave/leave';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import { useMenuDialog } from '@/lib/menuFocus';
import type { SharedProps } from '@/types';

/**
 * My Leave: this person's balances, the apply form, and their own history.
 *
 * ## One page for every surface
 *
 * The same reason `Pages/Shared/Profile.vue` and `Pages/Shared/Attendance.vue` are one page
 * each: applying for leave is a fact about the person and not about the shell they are looking
 * at. Part C §1 gives *Apply for own leave* to **every** role and says underneath the matrix
 * that the Accountant shell therefore carries My Leave — so the layout is picked from
 * `auth.user.surface` and **the Accountant applies in the Accountant shell**, at the same URL
 * an Admin uses, importing nothing from `Layouts/AdminLayout.vue` or `Pages/Admin/`.
 *
 * Nothing on this page is anybody else's. The controller scopes every row to the requester's
 * own employee record, and there is no id in the URL to point somewhere else.
 *
 * ## Zero is an answer
 *
 * A balance of nothing reads *0 days left*, not a blank: "you have none left" and "this type
 * does not apply to you" are different sentences. A person with no history yet gets an
 * `EmptyState` saying so, under an apply form that still works.
 */

defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'admin') {
            return AdminLayout;
        }

        return surface === 'accountant' ? AccountantLayout : EmployeeLayout;
    },
});

const props = defineProps<{
    subject: { id: number; name: string };
    balances: LeaveBalanceRow[];
    types: LeaveTypeOption[];
    requests: LeaveRequestRow[];
    permissions: { can_apply: boolean };
}>();

/** The request being amended after a correction request, if any. */
const amending = ref<LeaveRequestRow | null>(null);

/**
 * Anything still waiting on somebody, separated from the rest.
 *
 * A request that has been sent back for a correction is waiting on the READER and is the one
 * thing on this page they have to do something about, so it leads. A pending one is waiting on
 * an approver and is next. Everything settled is history underneath.
 *
 * The split is the two OPEN statuses rather than "not approved and not rejected", which is what it
 * used to be: that reading put a withdrawn request under *Waiting* (decision 5-19), where nothing is
 * waiting for anything. `LEAVE_OPEN_STATUSES` is `LeaveStatus::isOpen()` spelled for the client, so a
 * sixth status would land in History by default rather than in a queue it does not belong in.
 */
const open = computed(() => props.requests.filter((request) => LEAVE_OPEN_STATUSES.includes(request.status)));
const settled = computed(() => props.requests.filter((request) => ! LEAVE_OPEN_STATUSES.includes(request.status)));

function amend(request: LeaveRequestRow): void {
    amending.value = request;
}

/* ----------------------------------------------------------------- withdrawing */

/**
 * Taking a request back — decision 5-19.
 *
 * **It asks first.** A booked week disappearing off the leave calendar is not something to do on one
 * misplaced click, and the question names the type and the dates, because somebody with three
 * requests waiting cannot answer "Are you sure?".
 *
 * Focus comes back through `useMenuDialog` (`lib/menuFocus.ts`), which captures the *Withdraw* button
 * as the dialog opens. That button is gone once the request is withdrawn — `can_withdraw` turns false
 * — so this is decision 5-20's shape reached from the other direction. With the button gone the helper
 * falls back to the `<main>` region, which is a real focus stop every layout maintains for the skip
 * link; a heading would not be, and `.focus()` on something with no `tabindex` does nothing at all.
 */
const withdrawing = ref<LeaveRequestRow | null>(null);
const sending = ref(false);
const menu = useMenuDialog();

function askToWithdraw(request: LeaveRequestRow): void {
    menu.openFromMenu(() => {
        withdrawing.value = request;
    });
}

function closeWithdraw(): void {
    withdrawing.value = null;
    menu.returnFocus();
}

function confirmWithdraw(): void {
    const request = withdrawing.value;

    if (request === null || sending.value) {
        return;
    }

    sending.value = true;

    router.post(
        leaveRoutes.withdraw(request.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                sending.value = false;
                closeWithdraw();
            },
        },
    );
}
</script>

<template>
    <Head title="My leave" />

    <PageShell
        title="My leave"
        description="What you have left, what you have asked for, and what was decided."
    >
        <div class="flex min-w-0 flex-col gap-6">
            <!--
                Balances first: it is the number somebody opens this page to check, and the
                apply form below it is the thing they do about it.
            -->
            <section aria-labelledby="my-balances" class="flex min-w-0 flex-col gap-3">
                <h2 id="my-balances" class="text-base font-semibold tracking-tight">Balances</h2>

                <ul v-if="balances.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <li v-for="row in balances" :key="row.type.id">
                        <Card class="flex flex-col gap-1 p-4">
                            <p class="text-sm font-medium">{{ row.type.name }}</p>
                            <p class="text-2xl font-semibold tabular-nums">{{ row.balance_days }}</p>
                            <p class="text-xs text-muted-foreground">{{ formatBalance(row.balance_days) }}</p>
                        </Card>
                    </li>
                </ul>

                <!--
                    The uncapped types, said in words rather than as cards with no number on
                    them. Part D §9: Unpaid and Other have no balance, so a card reading 0 would
                    be a lie in the UI.
                -->
                <p class="text-xs text-muted-foreground">
                    Unpaid and Other have no balance — they are never refused for want of days, and Unpaid days are
                    recorded as unpaid. Nothing tops a balance up automatically; an Admin sets these.
                </p>
            </section>

            <section v-if="permissions.can_apply" aria-labelledby="apply-leave" class="flex min-w-0 flex-col gap-3">
                <h2 id="apply-leave" class="sr-only">Apply for leave</h2>
                <ApplyLeaveForm
                    :types="types"
                    :balances="balances"
                    :editing="amending"
                    @cancel="amending = null"
                />
            </section>

            <section aria-labelledby="open-requests" class="flex min-w-0 flex-col gap-3">
                <h2 id="open-requests" class="text-base font-semibold tracking-tight">Waiting</h2>

                <ul v-if="open.length" class="flex flex-col gap-3">
                    <li v-for="request in open" :key="request.id">
                        <LeaveRequestCard :request="request" @amend="amend" @withdraw="askToWithdraw" />
                    </li>
                </ul>

                <Card v-else class="p-4">
                    <EmptyState
                        :icon="Inbox"
                        title="Nothing waiting"
                        description="You have no leave requests waiting on a decision."
                    />
                </Card>
            </section>

            <section aria-labelledby="leave-history" class="flex min-w-0 flex-col gap-3">
                <h2 id="leave-history" class="text-base font-semibold tracking-tight">History</h2>

                <ul v-if="settled.length" class="flex flex-col gap-3">
                    <li v-for="request in settled" :key="request.id">
                        <LeaveRequestCard :request="request" />
                    </li>
                </ul>

                <Card v-else class="p-4">
                    <EmptyState
                        :icon="CalendarOff"
                        title="No leave yet"
                        description="Approved and refused requests are kept here, with the reason beside them."
                    />
                </Card>
            </section>
        </div>
    </PageShell>

    <!--
        The withdrawal confirmation (decision 5-19). It NAMES the type and the dates, because "Are
        you sure?" is not a question somebody with three requests waiting can answer — the same rule
        the Holidays delete dialog states. And it says what withdrawing does to the days, since
        freeing them again is the whole reason anybody presses it.
    -->
    <Dialog :open="withdrawing !== null" @update:open="(open) => { if (! open) { closeWithdraw(); } }">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    Withdraw {{ withdrawing?.type?.name ?? 'leave' }} for
                    {{ withdrawing ? formatWindow(withdrawing.start_date, withdrawing.end_date) : '' }}?
                </DialogTitle>
                <DialogDescription>
                    The request stays on your record marked Withdrawn, nobody has to rule on it, and those
                    days are free to book again. It cannot be un-withdrawn — ask again if you still need them.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="outline" :disabled="sending" @click="closeWithdraw">
                    Keep it
                </Button>
                <Button type="button" :disabled="sending" @click="confirmWithdraw">
                    {{ sending ? 'Withdrawing…' : 'Withdraw request' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
