<script setup lang="ts">
import { FileBarChart } from '@lucide/vue';
import { computed } from 'vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import ReportCell from '@/Components/Reports/ReportCell.vue';
import ReportCharts from '@/Components/Reports/ReportCharts.vue';
import type { ReportCellValue, ReportColumn, ReportResult, ReportRow } from '@/Components/Reports/reports';
import { useNavigationPending } from '@/lib/useNavigationPending';

/**
 * A `ReportResult`, rendered. **This is the whole renderer**, and it is the same one for all
 * sixteen reports and for both screens.
 *
 * It knows `ReportFormat` and nothing else: no report key appears in this file, in
 * `ReportCell.vue` or in `ReportCharts.vue`, and adding the next eight reports adds a builder
 * in PHP and nothing at all here. If a report ever needs a branch on its key, that is a gap in
 * the contract to be reported, not a branch to write.
 *
 * ## The order things appear in
 *
 * Charts, then the table, then the totals, then the notes. The pictures are the shape of the
 * answer and the table is the answer; the notes are what the answer does not cover, and they
 * sit under it because a caveat read after the figure is a caveat that was read.
 *
 * ## No rows means no picture and no footer
 *
 * When there is nothing to report the screen is the empty sentence the *server* wrote and
 * nothing else — not a chart of nothing, and not a footer summing nothing. `empty` is that
 * sentence, and it is the server's because "no tasks are late" and "nobody tracked time in
 * this window" are different pieces of news that only the builder can tell apart.
 *
 * ## Where the wide-table problem is solved
 *
 * In `DataTable`, and only there: below `md` it renders the same column defs as a stacked card
 * list, so a nine-column report on a 375 px phone is a list of labelled values rather than a
 * page-wide scrollbar. This component adds no second answer to that question.
 */
const props = defineProps<{
    /** Stable per screen — `DataTable` stores column and density preferences under it. */
    id: string;
    result: ReportResult;
    /** `settings.currency`, for every `money` cell. */
    currency: string;
    /** True when a filter is set, so the empty state offers a way back out of it. */
    filtersActive?: boolean;
}>();

const emit = defineEmits<{
    /** The filtered empty state's *Clear filters*. The screen owns what clearing means. */
    clear: [];
}>();

/** `DataTable` keys its rows by `id`; a report row is keyed only by column. */
interface ReportTableRow {
    id: number;
    cells: ReportRow;
}

const hasRows = computed(() => props.result.rows.length > 0);

/**
 * Setting a filter is a round trip, and a report's query is not always quick. The table
 * becomes its skeleton once a visit has been running 200 ms — under that it never flips, so a
 * fast report does not flash.
 */
const loading = useNavigationPending();

/**
 * The rows, each wrapped rather than spread.
 *
 * `{ id, cells }` and not `{ id, ...row }`: a report whose builder happens to name a column
 * `id` would otherwise have that column quietly overwritten by a row number. The column defs
 * read through `cells` with their own `value()`, which is what that hook is for.
 */
const rows = computed<ReportTableRow[]>(() =>
    props.result.rows.map((cells, index) => ({ id: index, cells })),
);

/**
 * `ReportColumn` → `ColumnDef`. Nothing is sortable: `ReportRequest` reads no `sort` or `dir`
 * (contract §2), and a header that pushed one would be a control the server ignores.
 */
const columns = computed<ColumnDef<ReportTableRow>[]>(() =>
    props.result.columns.map((column) => ({
        key: column.key,
        header: column.label,
        align: column.align === 'end' ? 'right' : 'left',
        value: (row: ReportTableRow) => row.cells[column.key] ?? null,
        // Text wraps; a figure or a status stays on its line.
        nowrap: column.format !== 'text',
    })),
);

/** One `#cell-<key>` slot per column, so every value goes through `ReportCell`. */
const cellSpecs = computed(() =>
    props.result.columns.map((column) => ({
        key: column.key,
        format: column.format,
        labels: column.labels,
        // Not a column itself: the href rides on the row under its own key and is never
        // printed as a cell. See `ReportColumn::linkedBy()`.
        linkKey: column.link_key,
        slot: `cell-${column.key}` as const,
    })),
);

/**
 * The footer row, as a labelled list rather than a `<tfoot>`.
 *
 * `DataTable` has no footer — it is a list table, and a list has no total. Rather than
 * inventing a second table to carry one, the totals are printed under it as "label: value"
 * pairs, which also means they cannot silently fall out of line with the columns below `md`,
 * where the table is a card list and there is no column to align with. `DataTable` growing a
 * `footer` slot is the better answer and is reported with this slice.
 *
 * Every figure here is the server's `SUM()` over the same query that produced the rows.
 * Nothing on this page adds two values together.
 */
/**
 * A linked cell's href, read off the row under the column's `link_key`.
 *
 * Returns null for anything that is not a non-empty string, so a row that simply has no link —
 * an "Unassigned" bucket, a project the reader may not open — renders as plain text rather than
 * as a link to nowhere. The server decides that by omitting the key.
 */
function hrefFor(linkKey: string | undefined, row: unknown): string | null {
    if (linkKey === undefined || row === null || typeof row !== 'object') {
        return null;
    }

    // Through `cells`, not off the row. A table row here is the `{ id, cells }` wrapper above —
    // the server's row is one level down, and reading the wrapper found nothing, silently, so
    // every linked cell rendered as plain text and looked exactly like a report that had not
    // asked for links. Caught by looking at the screen, not by a type: `row` is `unknown` at
    // this boundary, so nothing could have complained.
    const cells = (row as Record<string, unknown>).cells;

    if (cells === null || typeof cells !== 'object') {
        return null;
    }

    const value = (cells as Record<string, unknown>)[linkKey];

    return typeof value === 'string' && value !== '' ? value : null;
}

const totals = computed(() => {
    const footer = props.result.totals;

    if (!footer || !hasRows.value) {
        return [];
    }

    return props.result.columns
        .filter((column: ReportColumn) => footer[column.key] !== undefined && footer[column.key] !== null)
        .map((column: ReportColumn) => ({
            key: column.key,
            label: column.label,
            format: column.format,
            labels: column.labels,
            value: footer[column.key] ?? null,
        }));
});
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <ReportCharts v-if="hasRows" :charts="result.charts" :currency="currency" />

        <DataTable
            :id="id"
            :columns="columns"
            :rows="rows"
            :loading="loading"
            :filters-active="filtersActive ?? false"
            :empty-icon="FileBarChart"
            empty-title="Nothing to report"
            :empty-description="result.empty"
            filtered-title="Nothing matches these filters"
            :filtered-description="result.empty"
            @clear="emit('clear')"
        >
            <template v-for="spec in cellSpecs" :key="spec.key" #[spec.slot]="{ value, row }">
                <!-- `DataTable` types a cell slot's value as `unknown`; a report cell is a scalar. -->
                <ReportCell
                    :value="(value as ReportCellValue)"
                    :format="spec.format"
                    :currency="currency"
                    :labels="spec.labels"
                    :href="hrefFor(spec.linkKey, row)"
                />
            </template>
        </DataTable>

        <div
            v-if="totals.length > 0"
            class="flex min-w-0 flex-wrap items-baseline gap-x-6 gap-y-3 rounded-xl border bg-muted p-4"
        >
            <p class="text-sm font-medium">Totals</p>
            <dl class="flex min-w-0 flex-wrap items-baseline gap-x-6 gap-y-3">
                <div v-for="total in totals" :key="total.key" class="flex min-w-0 items-baseline gap-2">
                    <dt class="text-xs text-muted-foreground">{{ total.label }}</dt>
                    <dd class="text-sm font-medium">
                        <ReportCell
                            :value="total.value"
                            :format="total.format"
                            :currency="currency"
                            :labels="total.labels"
                        />
                    </dd>
                </div>
            </dl>
        </div>

        <!-- Polish 007: the server's caveats are no longer printed under the report. -->
    </div>
</template>
