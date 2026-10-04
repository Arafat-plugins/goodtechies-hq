<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Tags } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type {
    ExpenseRecord,
    FinanceCategory,
    FinanceMonth,
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
 * The expense ledger: one month of money out (Part D §13, Phase 8).
 *
 * The mirror of `Pages/Shared/Finance/Income.vue`, and that file carries the argument: one
 * shared page picking its layout from `auth.user.surface`, the month in the URL, every total
 * read from `FinanceService::monthlyRollup()` rather than summed here, and the form as a real
 * route rather than a flag.
 *
 * **There is no project picker**, because Part D §20 gives an expense no project column —
 * *Project Cost* is a category, not a reference (decision 8-17). `projects` is passed empty to
 * the one form component both ledgers share, which is how that component knows to leave the
 * field out rather than draw a disabled one.
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
    records: ExpenseRecord[];
    totals: RollupSide;
    categories: FinanceCategory[];
    form: { mode: 'create' | 'edit'; record: ExpenseRecord | null } | null;
    currency: string;
    today: string;
    permissions: { can_create: boolean };
}>();

const ADD_BUTTON_ID = 'finance-expenses-add';

/**
 * Decision 5-20, through the one helper (`lib/menuFocus.ts`): the row's `⋯` trigger is captured
 * while the menu is still open and focus goes back to it, or to the Add control when the row it
 * belonged to has just been deleted.
 */
const menu = useMenuDialog(ADD_BUTTON_ID);

const indexHref = computed(() => financeRoutes.expenses.index(props.month.value));

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
        router.get(financeRoutes.expenses.edit(record.id, props.month.value), {}, { preserveScroll: true });
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

    router.delete(financeRoutes.expenses.destroy(record.id), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            pending.value = null;
            menu.returnFocus();
        },
    });
}

</script>

<template>
    <Head :title="`Expenses — ${month.label}`" />

    <PageShell
        title="Expenses"
        description="Every payment out, a month at a time. The totals beside the list are worked out from these rows, which is also what the finance dashboard and the monthly report read."
        :breadcrumb="[{ label: 'Finance' }, { label: 'Expenses' }]"
    >
        <template #actions>
            <Button as-child variant="outline">
                <Link :href="financeRoutes.categories.index()">
                    <Tags aria-hidden="true" />
                    Categories
                </Link>
            </Button>
            <Button v-if="permissions.can_create" :id="ADD_BUTTON_ID" as-child>
                <Link :href="financeRoutes.expenses.create(month.value)">
                    <Plus aria-hidden="true" />
                    Record expense
                </Link>
            </Button>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <!-- Polish 013: month, the table's controls and the page's actions on one row. -->
            <div class="flex min-w-0 flex-wrap items-center gap-3">
                <FinanceMonthNav :month="month" :href="(value) => financeRoutes.expenses.index(value)" />
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
                    kind="expense"
                    :records="records"
                    :currency="currency"
                    :month-label="month.label"
                    @edit="edit"
                    @remove="askRemove"
                >
                    <template #empty-action>
                        <Button v-if="permissions.can_create" as-child>
                            <Link :href="financeRoutes.expenses.create(month.value)">
                                <Plus aria-hidden="true" />
                                Record expense
                            </Link>
                        </Button>
                    </template>
                </LedgerTable>
            </div>

            <div class="min-w-0 lg:max-w-md">
                <LedgerTotals
                    title="This month, by category"
                    :caption="`Expenses by category for ${month.label}`"
                    :side="totals"
                    :currency="currency"
                    :total-label="'Total expenses'"
                    :empty-text="`No expenses are recorded in ${month.label}, so there is nothing to total.`"
                />
            </div>
        </div>

        <LedgerFormDialog
            :open="form !== null"
            kind="expense"
            :record="form?.record ?? null"
            :categories="categories"
            :projects="[]"
            :currency="currency"
            :today="today"
            @close="closeForm"
        />

        <LedgerDeleteDialog
            kind="expense"
            :record="pending"
            :currency="currency"
            :deleting="deleting"
            @cancel="cancelRemove"
            @confirm="confirmRemove"
        />
    </PageShell>
</template>
