<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { FilterDef } from '@/Components/FilterBar.vue';
import FilterBar from '@/Components/FilterBar.vue';
import type {
    ReportFilterKey,
    ReportFilterOptions,
    ReportFilterValues,
    ReportOption,
    ReportSummary,
} from '@/Components/Reports/reports';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { pushQuery, queryOf } from '@/lib/tableState';

/**
 * The chips a report wears — built from the filters the *server* said this report accepts.
 *
 * Every value is in the query string, so a filtered report is an address somebody can paste
 * to a colleague. That is the whole reason this is `FilterBar` in chip mode and not a form.
 *
 * ## Two things the shared bar could not do, and how each was answered
 *
 * 1. **`FilterBar` used to always render a search input.** `ReportRequest` reads five keys —
 *    `from`, `to`, `employee`, `project`, `client` — and ignores everything else (contract
 *    §2), so a search box on a report would be a control the server ignores, which is
 *    DESIGN.md §5.11 and worse than no box: somebody who types in it and gets the same rows
 *    back concludes the report is broken. The bar now takes **`searchable`**, defaulting to
 *    `true` so no earlier screen changed. That is a prop on the shared bar rather than a
 *    stylesheet rule reaching into its markup from out here — a selector naming another
 *    component's internals is a coupling that breaks silently the day that markup is
 *    rearranged, and nothing would fail.
 * 2. **A `date-range` filter writes `<key>_from` / `<key>_to`.** The contract's date keys are
 *    `from` and `to`, with no prefix, so no `FilterDef` can produce them. The range is
 *    therefore two date fields in the bar's own `extra` slot — the same slot the Tasks bar
 *    puts its checkboxes in — writing the contract's two keys directly. This one stays a
 *    composition rather than a prop: a bar that could be told to drop a filter's prefix would
 *    be a bar with two query-string conventions in it.
 */
const props = defineProps<{
    report: ReportSummary;
    filters: ReportFilterValues;
    options: ReportFilterOptions;
}>();

const accepts = (key: ReportFilterKey): boolean => props.report.filters.includes(key);

const asOptions = (list: ReportOption[] | undefined) =>
    (list ?? []).map((option) => ({ value: String(option.id), label: option.name }));

/**
 * One chip per id filter this report accepts and has options for.
 *
 * A filter with no options is not offered: an empty picker is a door onto nothing. The keys
 * are the contract's own — `employee`, `project`, `client` — so a chip writes exactly what
 * `ReportRequest` reads.
 */
const filterDefs = computed<FilterDef[]>(() => {
    const defs: FilterDef[] = [];

    if (accepts('employee') && (props.options.employees?.length ?? 0) > 0) {
        defs.push({
            key: 'employee',
            label: 'Employee',
            kind: 'select',
            options: asOptions(props.options.employees),
            searchPlaceholder: 'Search people…',
        });
    }

    if (accepts('project') && (props.options.projects?.length ?? 0) > 0) {
        defs.push({
            key: 'project',
            label: 'Project',
            kind: 'select',
            options: asOptions(props.options.projects),
            searchPlaceholder: 'Search projects…',
        });
    }

    if (accepts('client') && (props.options.clients?.length ?? 0) > 0) {
        defs.push({
            key: 'client',
            label: 'Client',
            kind: 'select',
            options: asOptions(props.options.clients),
            searchPlaceholder: 'Search clients…',
        });
    }

    return defs;
});

const hasRange = computed(() => accepts('date_range'));

/**
 * Is the window somebody's choice, or the month the server defaulted to?
 *
 * It is read from the **URL**, not from the echoed values: `ReportRequest` defaults `from` and
 * `to` to the current calendar month, so the payload always carries a range and a bar that
 * read it would offer to clear September for arriving in it — the same distinction
 * `taskFiltersActive` draws about a Calendar's own month.
 *
 * It reads Inertia's own `page.url` rather than `window.location`, so it is recomputed after a
 * visit — which is exactly what setting a date does. `FilterBar` reads its chips the same way.
 */
const page = usePage();

const rangeChosen = computed(() => {
    const query = queryOf(page.url);

    return query.from !== undefined || query.to !== undefined;
});

/** Nothing to narrow by — no bar at all, rather than an empty one. */
const anything = computed(() => hasRange.value || filterDefs.value.length > 0);

function setRange(key: 'from' | 'to', value: string): void {
    pushQuery({ [key]: value === '' ? null : value });
}
</script>

<template>
    <div v-if="anything" class="min-w-0">
        <FilterBar
            page-actions
            :search="null"
            :searchable="false"
            :filters="filterDefs"
            :extra-active="rangeChosen"
        >
            <template v-if="hasRange" #extra>
                <div class="flex min-w-0 items-center gap-2">
                    <Label for="report-filter-from" class="font-normal whitespace-nowrap">From</Label>
                    <Input
                        id="report-filter-from"
                        type="date"
                        class="h-9 w-40"
                        :model-value="filters.from ?? ''"
                        @update:model-value="(value) => setRange('from', String(value ?? ''))"
                    />
                </div>
                <div class="flex min-w-0 items-center gap-2">
                    <Label for="report-filter-to" class="font-normal whitespace-nowrap">To</Label>
                    <Input
                        id="report-filter-to"
                        type="date"
                        class="h-9 w-40"
                        :model-value="filters.to ?? ''"
                        @update:model-value="(value) => setRange('to', String(value ?? ''))"
                    />
                </div>
            </template>
        </FilterBar>
    </div>
</template>
