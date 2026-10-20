<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Tags } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import type {
    CurrencyOption,
    ExpenseRecord,
    FinanceCategory,
    FinanceMonth,
    FinanceProject,
    LedgerFilters,
    LedgerRecord,
    RecurringExpense,
    RollupCurrencySide,
} from '@/Components/Finance/finance';
import { financeRoutes } from '@/Components/Finance/finance';
import FinanceMonthNav from '@/Components/Finance/FinanceMonthNav.vue';
import LedgerDeleteDialog from '@/Components/Finance/LedgerDeleteDialog.vue';
import LedgerFormDialog from '@/Components/Finance/LedgerFormDialog.vue';
import LedgerTable from '@/Components/Finance/LedgerTable.vue';
import LedgerTotals from '@/Components/Finance/LedgerTotals.vue';
import RecurringExpensesCard from '@/Components/Finance/RecurringExpensesCard.vue';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import PageActionsHost from '@/Components/PageActionsHost.vue';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
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
 * Like income, an expense may name a project and be billable to the client (decision 8-17b,
 * superseding 8-17). `projects` arrives from the server only while the form is open, and is
 * passed to the one form component both ledgers share.
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
    categories: FinanceCategory[];
    projects: FinanceProject[];
    form: { mode: 'create' | 'edit'; record: ExpenseRecord | null } | null;
    currency: string;
    today: string;
    permissions: { can_create: boolean };
    /** The list filters as the server applied them (`category`, `currency`, `project`, `q`). */
    filters: LedgerFilters;
    /** The currencies a record may be kept in. */
    currencies: CurrencyOption[];
    /** The currency a new record starts in. */
    defaultCurrency: string;
    /** This side's totals, one entry per currency. Never added together. */
    totals_by_currency: RollupCurrencySide[];
    /** Repeating expenses (VPS, domain, hosting). Empty for anyone who may not manage them. */
    recurring: RecurringExpense[];
    /** How many repeating expenses are due and waiting to be recorded by hand. */
    dueRecurring: number;
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

/* --------------------------------------------------------------------- filters */

/** reka's Select has no empty value, so "no filter" is a sentinel rather than `''`. */
const ALL = 'all';

const searchId = useId();
const categoryFilterId = useId();
const projectFilterId = useId();

/** The active filters as query parameters, empty ones left out. */
function filterQuery(overrides: Partial<LedgerFilters> = {}): Record<string, string> {
    const filters = { ...props.filters, ...overrides };
    const query: Record<string, string> = {};

    if (filters.category !== null) {
        query.category = String(filters.category);
    }

    if (filters.currency) {
        query.currency = filters.currency;
    }

    if (filters.project !== null) {
        query.project = String(filters.project);
    }

    if (filters.q.trim() !== '') {
        query.q = filters.q.trim();
    }

    return query;
}

const hasFilters = computed(() => Object.keys(filterQuery()).length > 0);

function applyFilters(overrides: Partial<LedgerFilters>): void {
    router.get(
        window.location.pathname,
        { month: props.month.value, ...filterQuery(overrides) },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/** A month link that keeps the active filters. */
function monthHref(value: string): string {
    const url = financeRoutes.expenses.index(value);
    const query = new URLSearchParams(filterQuery()).toString();

    return query === '' ? url : `${url}${url.includes('?') ? '&' : '?'}${query}`;
}

const search = ref(props.filters.q);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

function onSearch(value: string | number): void {
    search.value = String(value);
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters({ q: search.value }), 300);
}

watch(
    () => props.filters.q,
    (value) => {
        if (value !== search.value.trim()) {
            search.value = value;
        }
    },
);

const categoryFilter = computed({
    get: () => (props.filters.category === null ? ALL : String(props.filters.category)),
    set: (value: string) => applyFilters({ category: value === ALL ? null : Number(value) }),
});

const projectFilter = computed({
    get: () => (props.filters.project === null ? ALL : String(props.filters.project)),
    set: (value: string) =>
        applyFilters({ project: value === ALL ? null : value === 'none' ? 'none' : Number(value) }),
});

function clearFilters(): void {
    clearTimeout(searchTimer);
    search.value = '';
    router.get(
        window.location.pathname,
        { month: props.month.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/** "Buffalo Modular — SEO · buffalomodular.com". */
function projectLabel(project: FinanceProject): string {
    return project.domain ? `${project.name} · ${project.domain}` : project.name;
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
                <FinanceMonthNav :month="month" :href="monthHref" />
                <PageActionsHost />
            </div>

            <Alert v-if="dueRecurring > 0">
                <AlertDescription>
                    {{ dueRecurring }} repeating {{ dueRecurring === 1 ? 'expense' : 'expenses' }} due — record them below
                    in Repeating expenses.
                </AlertDescription>
            </Alert>

            <!--
                The ledger gets the WHOLE width and the totals sit under it, rather than the two
                sharing a row. `DataTable`'s card is `overflow-hidden` with no horizontal
                scroller, so a table wider than its container does not scroll — it is **clipped**,
                and the column that goes first is the last one, which is the row's ⋯ menu. Six
                columns squeezed into two thirds of 1280 px did exactly that. Totals under the
                rows is also how a ledger has always been laid out.
            -->
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex min-w-0 flex-col gap-1">
                    <Label :for="searchId" class="text-xs text-muted-foreground">Search</Label>
                    <Input
                        :id="searchId"
                        :model-value="search"
                        type="search"
                        placeholder="Search notes"
                        aria-label="Search notes"
                        class="w-full sm:w-56"
                        @update:model-value="onSearch"
                    />
                </div>

                <div class="flex min-w-0 flex-col gap-1">
                    <Label :for="categoryFilterId" class="text-xs text-muted-foreground">Category</Label>
                    <Select v-model="categoryFilter">
                        <SelectTrigger :id="categoryFilterId" class="w-full sm:w-44">
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All categories</SelectItem>
                            <SelectItem v-for="category in categories" :key="category.id" :value="String(category.id)">
                                {{ category.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex min-w-0 flex-col gap-1">
                    <Label :for="projectFilterId" class="text-xs text-muted-foreground">Project</Label>
                    <Select v-model="projectFilter">
                        <SelectTrigger :id="projectFilterId" class="w-full sm:w-56">
                            <SelectValue placeholder="All projects" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All projects</SelectItem>
                            <SelectItem value="none">Not linked to a project</SelectItem>
                            <SelectItem v-for="project in projects" :key="project.id" :value="String(project.id)">
                                {{ projectLabel(project) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <Button v-if="hasFilters" variant="ghost" size="sm" @click="clearFilters">Clear filters</Button>
            </div>

            <div class="min-w-0">
                <LedgerTable
                    kind="expense"
                    :records="records"
                    :filtered="hasFilters"
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
                    :sides="totals_by_currency"
                    :total-label="'Total expenses'"
                    :empty-text="`No expenses are recorded in ${month.label}, so there is nothing to total.`"
                />
            </div>

            <div class="min-w-0">
                <RecurringExpensesCard
                    :items="recurring"
                    :categories="categories"
                    :projects="projects"
                    :currencies="currencies"
                    :default-currency="defaultCurrency"
                    :today="today"
                    :can-manage="permissions.can_create"
                />
            </div>
        </div>

        <LedgerFormDialog
            :open="form !== null"
            kind="expense"
            :record="form?.record ?? null"
            :categories="categories"
            :projects="projects"
            :currencies="currencies"
            :default-currency="defaultCurrency"
            :today="today"
            @close="closeForm"
        />

        <LedgerDeleteDialog
            kind="expense"
            :record="pending"
            :deleting="deleting"
            @cancel="cancelRemove"
            @confirm="confirmRemove"
        />
    </PageShell>
</template>
