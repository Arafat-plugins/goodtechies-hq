<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileChartColumn, Receipt, Scale, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import FinanceCategoryChart from '@/Components/Finance/FinanceCategoryChart.vue';
import FinanceMonthNav from '@/Components/Finance/FinanceMonthNav.vue';
import LedgerTotals from '@/Components/Finance/LedgerTotals.vue';
import type { FinanceMonth, MonthlyRollup } from '@/Components/Finance/finance';
import { financeRoutes, formatMoney } from '@/Components/Finance/finance';
import { moneyValue } from '@/Components/Finance/financeReport';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import { Button } from '@/Components/ui/button';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * The Finance dashboard — Part D §13's *"this month income / expense / net, by category"*.
 *
 * **One shared page on one route, not one per shell.** Whose money this is belongs to the
 * agency and not to the shell somebody is looking at, which is why `routes/shared.php` carries
 * the route and why this picks its layout from the viewer's own surface, exactly as
 * `Pages/Shared/Messages.vue`, `Leave.vue` and `Attendance.vue` do. Nobody without
 * `finance.view` reaches it — the route group is the gate, and an Employee is refused there
 * rather than shown a thinner version of this page.
 *
 * **The month lives in the URL** (`?month=YYYY-MM`), defaulting to the one we are in, so a
 * month is an address somebody can send, the back button steps through months, and the Company
 * dashboard's Row 3 links straight to the month it was counting.
 *
 * ## Nothing on this page is computed here
 *
 * Every figure is a string the server made — `FinanceService::monthlyRollup()`, whose net is
 * integer cents and never a subtraction of two floats. `formatMoney()` turns a string into
 * text; `moneyValue()` turns one into a bar's length. There is no third thing done to a number
 * on this screen, and in particular nothing is added up.
 *
 * ## An empty month says so, and an empty CATEGORY is simply not here
 *
 * A side with no rows gets a sentence from `LedgerTotals` saying the month has none. A category
 * with no rows is **absent from the list rather than shown as $0.00** — the rollup reports what
 * happened, and inventing zero rows to fill a layout is how a report starts lying. The page
 * says that in words under the heading, so a reader looking for *Other* knows why it is missing
 * rather than assuming the screen is broken.
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
    /** The month in the URL, and the two either side of it, as the server built them. */
    month: FinanceMonth & { is_current: boolean };
    rollup: MonthlyRollup;
    /** `settings.currency`. */
    currency: string;
    reportHref: string;
}>();

/**
 * The net's sub-line. **Words, never colour**: a month that cost more than it earned is told
 * apart by the minus sign on the figure and by this sentence, not by a tint (DESIGN.md §5.6).
 */
const netSub = computed(() => {
    if (moneyValue(props.rollup.net) < 0) {
        return 'The month cost more than it earned';
    }

    return moneyValue(props.rollup.net) === 0
        ? 'Income and expenses met exactly'
        : 'What came in, less what went out';
});

const isEmptyMonth = computed(
    () => props.rollup.income.categories.length === 0 && props.rollup.expenses.categories.length === 0,
);
</script>

<template>
    <Head :title="`Finance — ${month.label}`" />

    <PageShell title="Finance" description="What came in and what went out, by category, one month at a time.">
        <template #actions>
            <Button as-child variant="outline">
                <Link :href="reportHref">
                    <FileChartColumn aria-hidden="true" />
                    Monthly report
                </Link>
            </Button>
        </template>

        <section aria-labelledby="finance-month" class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-0.5">
                    <h2 id="finance-month" class="text-lg font-semibold tracking-tight">{{ month.label }}</h2>
                    <!--
                        Said in words rather than left for the reader to work out from the date:
                        somebody landing here needs to know whether the figures are final.
                    -->
                    <p class="text-xs text-muted-foreground">
                        {{ month.is_current ? 'This month, still in progress' : 'A closed month' }}
                    </p>
                </div>

                <FinanceMonthNav :month="month" :href="financeRoutes.dashboard" />
            </div>

            <!-- The three figures Part D §13 names, in the order it names them. -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard
                    label="Income"
                    :value="formatMoney(rollup.income.total, currency)"
                    sub="Recorded this month"
                    :icon="TrendingUp"
                />
                <StatCard
                    label="Expenses"
                    :value="formatMoney(rollup.expenses.total, currency)"
                    sub="Recorded this month"
                    :icon="Receipt"
                />
                <StatCard
                    label="Net"
                    :value="formatMoney(rollup.net, currency)"
                    :sub="netSub"
                    :icon="Scale"
                />
            </div>
        </section>

        <!--
            By category. Each column is the picture over the figures: `FinanceCategoryChart`
            draws the bars and hides itself from assistive tech, `LedgerTotals` is the one
            accessible copy of the numbers.
        -->
        <section aria-labelledby="finance-by-category" class="flex min-w-0 flex-col gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <h2 id="finance-by-category" class="text-base font-semibold tracking-tight">By category</h2>
            </div>

            <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-4">
                    <FinanceCategoryChart title="Income by category" :side="rollup.income" label="Income" />
                    <LedgerTotals
                        title="Income"
                        :caption="`Income by category, ${month.label}`"
                        :side="rollup.income"
                        :currency="currency"
                        empty-text="No income is recorded against this month yet."
                        total-label="Total income"
                    />
                </div>

                <div class="flex min-w-0 flex-col gap-4">
                    <FinanceCategoryChart title="Expenses by category" :side="rollup.expenses" label="Expenses" />
                    <LedgerTotals
                        title="Expenses"
                        :caption="`Expenses by category, ${month.label}`"
                        :side="rollup.expenses"
                        :currency="currency"
                        empty-text="No expense is recorded against this month yet."
                        total-label="Total expenses"
                    />
                </div>
            </div>
        </section>

        <p v-if="isEmptyMonth" class="text-sm text-muted-foreground">
            {{ month.label }} has nothing in the books yet. Step to another month with the arrows above, or
            <Link :href="reportHref" class="underline underline-offset-4">open the monthly report</Link>
            to see the year around it.
        </p>
    </PageShell>
</template>
