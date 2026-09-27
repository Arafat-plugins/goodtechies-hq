<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarOff,
    FileText,
    HandCoins,
    MessageSquareWarning,
    Plus,
    Receipt,
    Scale,
    TrendingUp,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import AttentionList, { type AttentionItem } from '@/Components/Dashboard/AttentionList.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { formatMoney } from '@/Components/Finance/finance';
import type { FinanceMonthSummary } from '@/Components/Finance/financeReport';
import type { DashboardPayrollCard } from '@/Components/Payroll/payslip';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge, { type StatusKey } from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { DASHBOARD_POLL_MS } from '@/Components/Realtime/live';
import { useLiveProps } from '@/Components/Realtime/reload';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';

defineOptions({ layout: AccountantLayout });

/**
 * The Accountant's landing screen — *"finance-only summary"* (Part D §3), real from Phase 10.
 *
 * It answers the one question Part D §3 gives this surface: **"What came in and what went out?"**
 * So it is this month's money, then the months still waiting on the reader, then the two things
 * Part C §1 grants this shell beyond finance — their own leave and their own payslip. There is no
 * task count here, no project, no attendance and no headcount: the Accountant has ❌ on that
 * whole column of the matrix, and none of it is fetched rather than fetched and hidden.
 *
 * Nothing on this page adds anything up. The three real figures are
 * `FinanceService::monthlyRollup()`'s — the same call `/finance` and the Company dashboard make —
 * the payroll figure is PostgreSQL's `SUM(net_salary)` for the month, and the payslip is
 * `PayrollItemResource`. Every card opens the screen its number came from.
 */

/** One row of "Outstanding": a payroll month, what it is waiting for in words, and its screen. */
interface OutstandingRow {
    id: string;
    title: string;
    meta: string;
    href: string;
    tone: 'default' | 'urgent';
}

const props = defineProps<{
    greetingName: string;
    today: string;
    /**
     * This month's income, expense, payroll and operating result.
     *
     * `{}` for a viewer without `finance.view` — absent from the payload rather than zeroed
     * (Part C §1), which is why every key is optional. `payroll` is `null`, never `0`, when the
     * month has no period yet; `payroll_note` says which of the two blanks it is in words.
     */
    finance: FinanceMonthSummary & DashboardPayrollCard;
    /**
     * The payroll months `PayrollStatus::isOpenToAccountant()` says this reader may still act on.
     *
     * Empty is an answer: nothing is open. Invoices and expense approvals are deliberately not
     * sources here — there is no invoicing in this build and an expense is recorded rather than
     * approved — and the panel's note says so, so an empty panel cannot imply that two things
     * nobody built are clear.
     */
    outstanding: OutstandingRow[];
    /**
     * This person's own leave (Part C §1: *"the ACCOUNTANT may apply for own leave"*).
     *
     * The same three numbers the Employee dashboard's card shows, from the same `LeaveService`.
     * `null` for somebody with no employee record.
     */
    leave: {
        balances: { name: string; days: number }[];
        pending: number;
        correction_requested: number;
        href: string;
    } | null;
    /**
     * This person's own newest payslip line (Part C §1: *"and view own payslip"*).
     *
     * Narrowed on the server by `PayrollItem::belongsToEmployeeOf()` — the Accountant holds
     * `payroll.view_others`, so *"what may they see"* is the whole company and *"which line is
     * theirs"* is a different question. `null` before their first line exists.
     */
    payslip: {
        item: {
            id: number;
            net_salary: string;
            period: { label: string; status_label: string | null; state: string | null } | null;
        };
        currency: string;
        href: string;
        index_href: string;
    } | null;
}>();

/** True once the server sent the money block at all — see the prop's docblock. */
const hasFinance = computed(() => props.finance.income !== undefined);

/**
 * The four figures, in the order Part D §3 lists them.
 *
 * Every value is the server's string rendered by `formatMoney()`; nothing here parses one back
 * into a number and nothing adds two together. The operating result says what it is **and what it
 * is not**, because a figure by that name which quietly omitted the largest cost would be worse
 * than no figure: expenses filed under the Payroll category are in it, and the payroll RUN —
 * a different table the rollup never reads — is not.
 */
const monthCards = computed(() => [
    {
        key: 'income',
        label: 'Income',
        value: formatMoney(props.finance.income ?? '0', props.finance.currency),
        sub: 'Recorded this month',
        icon: TrendingUp,
        href: '/finance/income',
    },
    {
        key: 'expense',
        label: 'Expenses',
        value: formatMoney(props.finance.expense ?? '0', props.finance.currency),
        sub: 'Recorded this month',
        icon: Receipt,
        href: '/finance/expenses',
    },
    {
        key: 'payroll',
        label: 'Payroll',
        // An em-dash and the sub-line's sentence only when the month has NO period yet. A zero
        // would claim we paid nobody.
        value:
            props.finance.payroll === null || props.finance.payroll === undefined
                ? undefined
                : formatMoney(props.finance.payroll, props.finance.currency),
        sub: props.finance.payroll_note,
        icon: HandCoins,
        href: props.finance.payroll_href ?? undefined,
    },
    {
        key: 'operating_result',
        label: 'Operating result',
        value: formatMoney(props.finance.operating_result ?? '0', props.finance.currency),
        sub: 'Income less expenses. The payroll run is not in it.',
        icon: Scale,
        href: props.finance.href,
    },
]);

/**
 * "Outstanding" as `AttentionList` rows — the shared card of linked rows, not a second one
 * (DESIGN.md §5.8). The icon is chosen here because an icon is a component and cannot travel as
 * JSON; the words, the tone and the destination are all the server's.
 */
const outstandingItems = computed<AttentionItem[]>(() =>
    props.outstanding.map((row) => ({
        id: row.id,
        icon: HandCoins,
        title: row.title,
        meta: row.meta,
        href: row.href,
        tone: row.tone,
    })),
);

/**
 * The capped type this person has most days of, for the leave card's one number.
 *
 * The same choice the Employee dashboard makes, and for the same reason: it answers "have I got
 * any leave" rather than "have I got any Annual leave". Ties keep the server's order.
 */
const largestBalance = computed(() =>
    (props.leave?.balances ?? []).reduce<{ name: string; days: number } | null>(
        (best, row) => (best === null || row.days > best.days ? row : best),
        null,
    ),
);

/* ---------------------------------------------------------------- keeping it current */

/**
 * The counters and the *Needs your attention* feed re-read themselves — POLISH-BACKLOG §A.3's
 * dashboard line, *"counters and 'needs your attention' refresh on a timer at minimum"*.
 *
 * **A timer, and only a timer, on both builds.** A dashboard has no channel and should not get
 * one: its props are a dozen aggregate queries over everything this reader may see, so a
 * "something changed" frame for it would have to be rung by every write in the application and
 * would say nothing useful when it arrived. §A.4's third rule is answered by saying that out
 * loud rather than by inventing a `dashboard.{user}` room.
 *
 * **Sixty seconds**, because nobody reads a dashboard as a clock and this is the heaviest
 * controller in the application — a partial reload runs it in full whether it answers thirteen
 * props or four (see `reload.ts`). Anything faster spends the client's machine on a number that
 * is the same number. The one figure on this screen that IS read as a clock, the running timer,
 * does not wait for this: it has its own heartbeat in `Components/Timer/timer.ts`.
 *
 * Named props rather than a bare reload: the dates, the holidays and the meetings on this screen
 * are not what changes minute to minute, and a full reload would replace every prop on the page
 * including ones a card holds local state against.
 *
 * Every prop on this screen is a counter — the month's money, what is outstanding, who is away,
 * this reader's own payslip — so the list is all four. An invoice recorded by an Admin upstairs
 * shows up here inside the minute.
 */
useLiveProps(['finance', 'outstanding', 'leave', 'payslip'], { intervalMs: DASHBOARD_POLL_MS });
</script>

<template>
    <Head title="Dashboard" />

    <PageShell title="Dashboard" :greeting="{ name: greetingName, today }">
        <!--
            The page's one primary action, and it is a real route now: recording an expense is
            what this reader opens the app to do. The disabled button with "Arrives in Phase 8"
            printed under it is gone — Phase 8 shipped the ledger it was waiting for.
        -->
        <template #actions>
            <Button as-child>
                <Link href="/finance/expenses/create">
                    <Plus aria-hidden="true" />
                    Record expense
                </Link>
            </Button>
        </template>

        <section aria-labelledby="this-month" class="flex flex-col gap-4">
            <h2 id="this-month" class="text-base font-semibold tracking-tight">
                This month<template v-if="finance.label"> — {{ finance.label }}</template>
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <template v-if="hasFinance">
                    <StatCard
                        v-for="card in monthCards"
                        :key="card.key"
                        :label="card.label"
                        :value="card.value"
                        :sub="card.sub"
                        :icon="card.icon"
                        :href="card.href"
                    />
                </template>
                <!--
                    Nobody who can reach this route lacks `finance.view` today. If that ever
                    changes, the block is ABSENT from the payload rather than zeroed (Part C §1),
                    and this is what that reader sees: the state in words, not four noughts.
                -->
                <EmptyState
                    v-else
                    :icon="Wallet"
                    title="No access to the books"
                    description="This month's figures are not yours to read, so they were not sent."
                />
            </div>
        </section>

        <!--
            What is still waiting on this reader. One real source — the payroll months
            `PayrollStatus::isOpenToAccountant()` says they may still change — and the note names
            the two things that are not sources and never will be, so an empty panel cannot be
            read as "the books are clear".
        -->
        <!-- `min-w-0`: `AttentionList`'s root `Card` carries none of its own (see the Company
             dashboard, where a long row pushed a 375 px page 51 px wide). -->
        <div class="min-w-0">
            <AttentionList
                title="Outstanding"
                :items="outstandingItems"
                :empty-icon="Wallet"
                empty-title="Nothing waiting on you"
                empty-description="No payroll month is open for you to fill in or calculate."
                note="Payroll months only. There is no invoicing in this build, and an expense is recorded rather than approved."
            />
        </div>

        <section aria-label="Me" class="grid gap-4 md:grid-cols-2">
            <!--
                My Leave and My Payslip — the two rows Part C §1 gives this shell beyond finance,
                real from Phases 5 and 9. Both are about the reader and nobody else.
            -->
            <div class="grid min-w-0 gap-4">
                <StatCard
                    v-if="leave"
                    size="compact"
                    label="Leave balance"
                    :value="largestBalance?.days ?? 0"
                    :sub="largestBalance ? `${largestBalance.name} — most days left` : 'No balance set yet'"
                    :icon="CalendarOff"
                    :href="leave.href"
                />
                <StatCard
                    v-if="leave"
                    size="compact"
                    label="Leave waiting"
                    :value="leave.pending"
                    :sub="leave.pending === 0 ? 'Nothing waiting on an approver' : 'Waiting on a decision'"
                    :icon="CalendarClock"
                    :href="leave.href"
                />
                <StatCard
                    v-if="leave && leave.correction_requested > 0"
                    size="compact"
                    label="Needs your correction"
                    :value="leave.correction_requested"
                    sub="Sent back to you — amend and send it again"
                    :icon="MessageSquareWarning"
                    :href="leave.href"
                />
            </div>

            <Card class="min-w-0 gap-4 p-6">
                <div class="flex min-w-0 items-center justify-between gap-4">
                    <h2 class="text-sm font-medium">My payslip</h2>
                    <Link
                        v-if="payslip"
                        :href="payslip.index_href"
                        class="shrink-0 text-xs text-muted-foreground underline-offset-4 hover:underline"
                    >
                        Every month
                    </Link>
                </div>
                <EmptyState
                    v-if="!payslip"
                    :icon="FileText"
                    title="No payslip yet"
                    description="Your line appears once the month's payroll draft has been created."
                />
                <div v-else class="flex min-w-0 flex-col gap-2">
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ formatMoney(payslip.item.net_salary, payslip.currency) }}
                    </p>
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <span class="text-xs text-muted-foreground">{{ payslip.item.period?.label }}</span>
                        <!--
                            The status prints its own word beside the tint — a payslip that is
                            still a draft must say so, because "Provisional" is the difference
                            between a receipt and a working figure (DESIGN.md §5.6).
                        -->
                        <StatusBadge
                            v-if="payslip.item.period?.state"
                            size="sm"
                            :status="payslip.item.period.state as StatusKey"
                            :label="payslip.item.period.status_label ?? undefined"
                        />
                    </div>
                    <Button as-child size="sm" variant="outline" class="self-start">
                        <Link :href="payslip.href">Open the payslip</Link>
                    </Button>
                </div>
            </Card>
        </section>
    </PageShell>
</template>
