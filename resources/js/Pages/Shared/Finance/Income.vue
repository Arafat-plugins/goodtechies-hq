<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Tags } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type {
    FinanceCategory,
    FinanceMonth,
    FinanceProject,
    IncomeRecord,
    LedgerRecord,
    RollupSide,
} from '@/Components/Finance/finance';
import { financeRoutes } from '@/Components/Finance/finance';
import FinanceMonthNav from '@/Components/Finance/FinanceMonthNav.vue';
import LedgerDeleteDialog from '@/Components/Finance/LedgerDeleteDialog.vue';
import LedgerFormDialog from '@/Components/Finance/LedgerFormDialog.vue';
import LedgerTable from '@/Components/Finance/LedgerTable.vue';
import LedgerTotals from '@/Components/Finance/LedgerTotals.vue';
import PageActionsHost from '@/Components/PageActionsHost.vue';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useMenuDialog } from '@/lib/menuFocus';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * The income ledger: one month of money in, and the form that writes it (Part D §13, Phase 8).
 *
 * **One shared page, not one per shell.** Whose money this is belongs to the agency and not to
 * the shell somebody is looking at, so `routes/shared.php` carries the endpoints behind
 * `can:finance.view` and this picks its layout from the viewer's own surface, exactly as
 * `Pages/Shared/Messages.vue` does. An Admin gets `AdminLayout`, the Accountant gets
 * `AccountantLayout`, and everybody else is refused by the route gate before this component
 * exists — which is the phase's *"Employees/Remote get 403 on every finance route"*.
 *
 * **The month is in the URL** (`?month=YYYY-MM`), so September is a link somebody can send, the
 * back button steps between months and a bookmark opens the month it was made in. Same shape as
 * the attendance month and the meetings calendar.
 *
 * **Every total on this page is the server's.** `totals` is one half of
 * `FinanceService::monthlyRollup()` — two SQL sums, computed and never stored (decision 8-10) —
 * and nothing in this file adds a column up. A second sum would be a second statement of a fact
 * about money, and the day it disagreed with the dashboard's neither would be provably right.
 *
 * **The form is a route, not a flag.** `/finance/income/create` and
 * `/finance/income/{id}/edit` render this same component with `form` filled, so a refresh keeps
 * the form open and a validation failure redirects to an address that renders it again with the
 * server's errors on it.
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
    month: FinanceMonth;
    records: IncomeRecord[];
    totals: RollupSide;
    categories: FinanceCategory[];
    /** Sent only while the form is open — the ledger itself has no use for a project list. */
    projects: FinanceProject[];
    form: { mode: 'create' | 'edit'; record: IncomeRecord | null } | null;
    currency: string;
    today: string;
    permissions: { can_create: boolean };
}>();

/** The id the Record control carries, so `returnFocus` can find it without a template ref. */
const ADD_BUTTON_ID = 'finance-income-add';

/**
 * Decision 5-20, through the one helper (`lib/menuFocus.ts`): the row's `⋯` trigger is captured
 * while the menu is still open and focus goes back to it, or to the Add control when the row it
 * belonged to has just been deleted.
 */
const menu = useMenuDialog(ADD_BUTTON_ID);

const indexHref = computed(() => financeRoutes.income.index(props.month.value));

/* ------------------------------------------------------------------ the form */

function closeForm(): void {
    router.get(indexHref.value, {}, { preserveScroll: true });
}

/**
 * Whichever way the form goes away — Cancel, Esc, or a save that redirected back to the ledger
 * — put the keyboard back on the control that opens it.
 *
 * A watcher rather than a callback on the close, because a successful save closes the dialog by
 * *navigating*: the server redirects to the index, this component re-renders with `form` null,
 * and no close handler ever runs. That path left focus on `<body>`, which drops a keyboard user
 * at the top of the document after every record they enter — the same shape of gap decision
 * 5-20 describes, arrived at from the other direction.
 */
watch(
    () => props.form !== null,
    (open, wasOpen) => {
        if (wasOpen && ! open) {
            menu.returnFocus();
        }
    },
);

/**
 * Editing comes from a `DropdownMenuItem`, and reka dismisses the menu on select while restoring
 * focus to the `⋯` trigger. Navigating in the same tick races that, so `openFromMenu` defers it by a
 * tick — and captures the trigger, so the watcher above can put focus back on the row's own `⋯`
 * instead of the Add button when the row is still there (decision 5-20, `lib/menuFocus.ts`).
 */
function edit(record: LedgerRecord): void {
    menu.openFromMenu(() => {
        router.get(financeRoutes.income.edit(record.id, props.month.value), {}, { preserveScroll: true });
    });
}

/* -------------------------------------------------------------------- deleting */

const pending = ref<LedgerRecord | null>(null);
const deleting = ref(false);

function askRemove(record: LedgerRecord): void {
    menu.openFromMenu(() => {
        pending.value = record;
    });
}

function cancelRemove(): void {
    pending.value = null;
    menu.returnFocus();
}

function confirmRemove(): void {
    const record = pending.value;

    if (!record || deleting.value) {
        return;
    }

    deleting.value = true;

    router.delete(financeRoutes.income.destroy(record.id), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            pending.value = null;
            // The row and its ⋯ button are gone, so the capture is off the page and focus falls
            // through to the Add control.
            menu.returnFocus();
        },
    });
}

</script>

<template>
    <Head :title="`Income — ${month.label}`" />

    <PageShell
        title="Income"
        description="Every payment in, a month at a time. The totals beside the list are worked out from these rows, which is also what the finance dashboard and the monthly report read."
        :breadcrumb="[{ label: 'Finance' }, { label: 'Income' }]"
    >
        <template #actions>
            <Button as-child variant="outline">
                <Link :href="financeRoutes.categories.index()">
                    <Tags aria-hidden="true" />
                    Categories
                </Link>
            </Button>
            <Button v-if="permissions.can_create" :id="ADD_BUTTON_ID" as-child>
                <Link :href="financeRoutes.income.create(month.value)">
                    <Plus aria-hidden="true" />
                    Record income
                </Link>
            </Button>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <!-- Polish 013: month, the table's controls and the page's actions on one row. -->
            <div class="flex min-w-0 flex-wrap items-center gap-3">
                <FinanceMonthNav :month="month" :href="(value) => financeRoutes.income.index(value)" />
                <PageActionsHost />
            </div>

            <!--
                The ledger gets the WHOLE width and the totals sit under it, rather than the two
                sharing a row. `DataTable`'s card is `overflow-hidden` with no horizontal
                scroller, so a table wider than its container does not scroll — it is **clipped**,
                and the column that goes first is the last one, which is the row's ⋯ menu. Six
                columns squeezed into two thirds of 1280 px did exactly that. Totals under the
                rows is also how a ledger has always been laid out.
            -->
            <div class="min-w-0">
                <LedgerTable
                    kind="income"
                    :records="records"
                    :currency="currency"
                    :month-label="month.label"
                    @edit="edit"
                    @remove="askRemove"
                >
                    <template #empty-action>
                        <Button v-if="permissions.can_create" as-child>
                            <Link :href="financeRoutes.income.create(month.value)">
                                <Plus aria-hidden="true" />
                                Record income
                            </Link>
                        </Button>
                    </template>
                </LedgerTable>
            </div>

            <div class="min-w-0 lg:max-w-md">
                <LedgerTotals
                    title="This month, by category"
                    :caption="`Income by category for ${month.label}`"
                    :side="totals"
                    :currency="currency"
                    :total-label="'Total income'"
                    :empty-text="`No income is recorded in ${month.label}, so there is nothing to total.`"
                />
            </div>
        </div>

        <LedgerFormDialog
            :open="form !== null"
            kind="income"
            :record="form?.record ?? null"
            :categories="categories"
            :projects="projects"
            :currency="currency"
            :today="today"
            @close="closeForm"
        />

        <LedgerDeleteDialog
            kind="income"
            :record="pending"
            :currency="currency"
            :deleting="deleting"
            @cancel="cancelRemove"
            @confirm="confirmRemove"
        />
    </PageShell>
</template>
