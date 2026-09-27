<script setup lang="ts">
import { Pencil, Receipt, Trash2, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import type { FinanceCategoryKind, IncomeRecord, LedgerRecord } from '@/Components/Finance/finance';
import { formatLedgerDate, formatMoney, ledgerNoun } from '@/Components/Finance/finance';
import { DropdownMenuItem } from '@/Components/ui/dropdown-menu';

/**
 * One month of one side of the ledger.
 *
 * The same component for income and for expenses, because they are the same table with one
 * extra column: Part D §20 gives an expense no project link, so `Project` is dropped rather
 * than rendered empty. Two components would have been the money column written twice, and the
 * money column is the one thing in this slice that must be written once.
 *
 * ## What it becomes at 360 px
 *
 * `DataTable`'s own stacked card list, which is what it does below `md`: the date as the card's
 * heading, then Category, Project, Amount and Notes as label/value pairs, with the row's `⋯`
 * menu at the right. Five or six columns across a 360 px phone is a horizontal scrollbar, not a
 * table, and this repo's accessibility floor forbids one at 360, 375, 768 or 1280.
 *
 * It is the **base** card and not the `#card` slot, and that was learned the hard way: filling
 * `#card` replaces the whole `<li>`, **including the `⋯` menu**, so a hand-laid-out card looked
 * better and left Edit and Delete unreachable on every phone. The per-cell slots below still
 * apply inside it, so the amount on a card goes through `formatMoney()` exactly as the amount in
 * the table does — which is the part that actually had to be right.
 *
 * ## Money
 *
 * The amount column is rendered through `formatMoney()` and never through `DataTable`'s
 * `cell: 'currency'`, which drops the decimals when they are zero. A column that is sometimes
 * `$860` and sometimes `$1,250.50` does not line up on its decimal point, and lining up is most
 * of what a ledger column is for.
 *
 * Nothing here is `sortable` and there is no paging: the controller orders by date and reads no
 * `sort` or `per_page`, and a header that pushed one it ignored would be a lie in the UI
 * (decisions 0.5-16, 0.5-19). A month is a month; it is not a page of a month.
 */
const props = defineProps<{
    kind: FinanceCategoryKind;
    records: LedgerRecord[];
    currency: string;
    /** The month's own label, so the table's accessible name says which month this is. */
    monthLabel: string;
}>();

const emit = defineEmits<{
    edit: [record: LedgerRecord];
    remove: [record: LedgerRecord];
}>();

const noun = computed(() => ledgerNoun(props.kind));

const isIncome = computed(() => props.kind === 'income');

/** Only an income row has one; `project` is not a key on an expense at all. */
function projectOf(record: LedgerRecord): IncomeRecord['project'] {
    return (record as IncomeRecord).project ?? null;
}

const columns = computed<ColumnDef<LedgerRecord>[]>(() => {
    const defined: ColumnDef<LedgerRecord>[] = [
        { key: 'date', header: 'Date', nowrap: true, hideable: false },
        { key: 'category', header: 'Category', nowrap: true, hideable: false, value: (row) => row.category?.name ?? '—' },
    ];

    if (isIncome.value) {
        defined.push({ key: 'project', header: 'Project', value: (row) => projectOf(row)?.name ?? '' });
    }

    defined.push(
        // Right-aligned and `nowrap`: a wrapped amount is two half-numbers.
        { key: 'amount', header: 'Amount', align: 'right', nowrap: true, hideable: false },
        { key: 'notes', header: 'Notes', value: (row) => row.notes ?? '' },
        { key: 'actions', header: 'Actions', cell: 'actions', headerHidden: true, hideable: false },
    );

    return defined;
});

/**
 * The accessible name of a row, which `DataTable` uses for the card heading and for the `⋯`
 * button's `aria-label` (`Actions for …`). It names the amount and the date, because that is
 * how a person identifies one of a dozen rows several of which are called *Maintenance* — and
 * because the page finds this exact string again to put focus back after the confirm dialog
 * closes.
 */
function rowLabel(record: LedgerRecord): string {
    const category = record.category?.name ?? 'Uncategorised';

    return `${category} ${noun.value} of ${formatMoney(record.amount, props.currency)} on ${formatLedgerDate(record.date)}`;
}
</script>

<template>
    <DataTable
        :id="`finance-${kind}-ledger`"
        :columns="columns"
        :rows="records"
        :row-label="rowLabel"
        :noun="noun"
        :empty-icon="isIncome ? TrendingUp : Receipt"
        :empty-title="`Nothing recorded in ${monthLabel}`"
        :empty-description="
            isIncome
                ? 'Income is entered a payment at a time — a category, an amount, the date it arrived and, where it belongs to one, the project. The month’s totals are worked out from these rows.'
                : 'Expenses are entered a payment at a time — a category, an amount and the date it went out. The month’s totals are worked out from these rows.'
        "
    >
        <template #empty-action>
            <slot name="empty-action" />
        </template>

        <template #cell-date="{ row }">
            <span class="tabular-nums">{{ formatLedgerDate(row.date) }}</span>
        </template>

        <!--
            The project, by name with its domain under it. Three keys arrive and two are shown:
            the domain is what tells four "Website Maintenance" rows apart (Part C §2), and
            there is no client name on this payload for any reader, on any surface.
        -->
        <template v-if="isIncome" #cell-project="{ row }">
            <span v-if="projectOf(row)" class="flex min-w-0 flex-col">
                <span class="truncate">{{ projectOf(row)?.name }}</span>
                <span v-if="projectOf(row)?.domain" class="truncate text-xs text-muted-foreground">
                    {{ projectOf(row)?.domain }}
                </span>
            </span>
            <span v-else class="text-muted-foreground">Not linked</span>
        </template>

        <template #cell-amount="{ row }">
            <span class="tabular-nums">{{ formatMoney(row.amount, currency) }}</span>
        </template>

        <!--
            Clamped to two lines, with the whole note on the element's `title` and in the card
            list below `md`. An un-clamped note ran to five lines in a narrow column and made
            every row of the seeded month taller than the row above it, which turns a ledger
            into a list of paragraphs. Two lines is enough to recognise a payment by.
        -->
        <template #cell-notes="{ row }">
            <span v-if="row.notes" class="line-clamp-2 text-muted-foreground" :title="row.notes">
                {{ row.notes }}
            </span>
            <span v-else class="sr-only">No notes</span>
        </template>

        <!--
            Both controls come from the row's OWN `permissions` block, resolved per record on
            the server by `IncomePolicy` / `ExpensePolicy` — never from a role compared here
            (decisions 2-28, 2-31).
        -->
        <template #row-actions="{ row }">
            <DropdownMenuItem v-if="row.permissions.can_update" @select="emit('edit', row)">
                <Pencil aria-hidden="true" />
                Edit {{ noun }}
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="row.permissions.can_delete"
                variant="destructive"
                @select="emit('remove', row)"
            >
                <Trash2 aria-hidden="true" />
                Delete {{ noun }}
            </DropdownMenuItem>
        </template>
    </DataTable>
</template>
