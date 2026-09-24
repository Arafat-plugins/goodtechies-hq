<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarOff, Inbox } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import ApplyLeaveForm from '@/Components/Leave/ApplyLeaveForm.vue';
import LeaveRequestCard from '@/Components/Leave/LeaveRequestCard.vue';
import type { LeaveBalanceRow, LeaveRequestRow, LeaveTypeOption } from '@/Components/Leave/leave';
import { formatBalance } from '@/Components/Leave/leave';
import PageShell from '@/Components/PageShell.vue';
import { Card } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';
import { usePagePoll } from '@/lib/pagePoll';

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

/** Part 0.5 refresh rule: an approver's decision, and the balance it spends. */
usePagePoll(['requests', 'balances']);

/** The request being amended after a correction request, if any. */
const amending = ref<LeaveRequestRow | null>(null);

/**
 * Anything still waiting on somebody, separated from the rest.
 *
 * A request that has been sent back for a correction is waiting on the READER and is the one
 * thing on this page they have to do something about, so it leads. A pending one is waiting on
 * an approver and is next. Everything settled is history underneath.
 */
const open = computed(() => props.requests.filter((request) => request.status !== 'approved' && request.status !== 'rejected'));
const settled = computed(() => props.requests.filter((request) => request.status === 'approved' || request.status === 'rejected'));

const applySection = ref<HTMLElement | null>(null);
const applyForm = ref<InstanceType<typeof ApplyLeaveForm> | null>(null);

/**
 * Answer a correction request: seed the form, then take the reader to it.
 *
 * The button is in the Waiting list and the form is above the balances, so on anything shorter
 * than a tall desktop the only effect of the click happened off screen and the page looked
 * broken. Moving the reader is the whole fix — `nextTick` first because the form only renders
 * its amend copy once `amending` is set, and focus last so a screen reader lands on the field
 * rather than being told the page scrolled.
 */
async function amend(request: LeaveRequestRow): Promise<void> {
    amending.value = request;

    await nextTick();

    const reduced =
        typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    applySection.value?.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
    applyForm.value?.focus();
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

            <section
                v-if="permissions.can_apply"
                ref="applySection"
                aria-labelledby="apply-leave"
                class="flex min-w-0 flex-col gap-3 scroll-mt-20"
            >
                <h2 id="apply-leave" class="sr-only">Apply for leave</h2>
                <ApplyLeaveForm
                    ref="applyForm"
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
                        <LeaveRequestCard :request="request" @amend="amend" />
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
</template>
