<script setup lang="ts" generic="T extends { id: number | string }">
import { usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ChevronRight,
    ChevronsUpDown,
    Inbox,
    MoreHorizontal,
    Rows2,
    Rows3,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import DataTableBulkBar from '@/Components/DataTable/DataTableBulkBar.vue';
import DataTableColumnMenu from '@/Components/DataTable/DataTableColumnMenu.vue';
import type { ColumnDef, Density, SortState, TableGroup } from '@/Components/DataTable/types';
import { ALIGN_JUSTIFY, ALIGN_TEXT, alignOf, isNumericCell } from '@/Components/DataTable/types';
import EmptyState from '@/Components/EmptyState.vue';
import type { PaginationLinks, PaginationMeta } from '@/Components/Pagination.vue';
import Pagination from '@/Components/Pagination.vue';
import SkeletonTable from '@/Components/Skeletons/SkeletonTable.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/Components/ui/toggle-group';
import {
    pushQuery,
    readDensity,
    readGroupState,
    readHiddenColumns,
    sortFrom,
    writeDensity,
    writeGroupState,
    writeHiddenColumns,
} from '@/lib/tableState';
import { cn } from '@/lib/utils';

/**
 * The list table every screen in this app uses.
 *
 * It is a thin component over `ui/table`, not a table engine: the server still does the
 * filtering, sorting and paging, and this only describes the result and pushes the query
 * string that asks for the next one. Below `md` the same column defs render as a stacked
 * card list, because eight columns on a 375 px phone is a horizontal scrollbar, not a table.
 *
 * A list that arrives already bucketed passes `groups` instead of `rows` and gets collapsible
 * group headers over the same rows, in the same two layouts. There is no second table
 * component: every list in this app is eventually going to want grouping, and two tables is
 * how a design system dies.
 */
const props = withDefaults(
    defineProps<{
        /** Stable id for this table. Column and density preferences are stored under it. */
        id: string;
        columns: ColumnDef<T>[];
        /** A flat list. Leave it off and pass `groups` for a grouped table. */
        rows?: T[];
        /**
         * Collapsible groups, in the order the server sent them. Passing this — even as an
         * empty array — switches the table into grouped mode and `rows` is ignored.
         */
        groups?: TableGroup<T>[];
        /**
         * Which grouping the `groups` came from (`status`, `assignee`, …). It only scopes
         * the collapsed-group memory, so switching the toggle does not have one variant's
         * keys close another's groups.
         */
        groupBy?: string;
        meta?: PaginationMeta;
        links?: PaginationLinks;
        /** Swaps the table for `SkeletonTable` while a visit is in flight. */
        loading?: boolean;
        /** True when any filter is set — it decides which empty state the viewer gets. */
        filtersActive?: boolean;
        selectable?: boolean;
        density?: Density;
        /** Shows the density toggle and the column menu. */
        viewOptions?: boolean;
        /**
         * Shows the rows-per-page select, which pushes `?per_page=`. Leave it off on a
         * screen whose controller does not read that parameter.
         */
        showPerPage?: boolean;
        perPageOptions?: number[];
        /** The accessible name of a row's ⋯ menu, and the card list's heading. */
        rowLabel?: (row: T) => string;
        /** Row click gets a pointer and keyboard affordance only when the screen wants one. */
        rowClickable?: boolean;
        /** Singular noun for the bulk bar: "3 projects selected". */
        noun?: string;
        emptyIcon?: Component;
        emptyTitle?: string;
        emptyDescription?: string;
        filteredTitle?: string;
        filteredDescription?: string;
    }>(),
    {
        rows: () => [],
        loading: false,
        filtersActive: false,
        selectable: false,
        viewOptions: true,
        showPerPage: false,
        rowClickable: false,
        emptyTitle: 'Nothing here yet',
        filteredTitle: 'Nothing matches these filters',
        filteredDescription: 'Clear a filter, or widen the search.',
    },
);

const emit = defineEmits<{
    'row-click': [row: T];
    /** The `filtered` empty state's way out; the screen clears its own filters. */
    clear: [];
    'update:selected': [ids: (string | number)[]];
    'sort-change': [sort: SortState];
    'per-page-change': [perPage: number];
}>();

const slots = defineSlots<
    {
        /** Replaces the whole stacked card below `md`. */
        card?: (props: { row: T }) => unknown;
        /** The items of a row's ⋯ menu. Its presence is what adds the actions column. */
        'row-actions'?: (props: { row: T }) => unknown;
        /** Buttons for the bulk bar. */
        'bulk-actions'?: (props: { selected: (string | number)[] }) => unknown;
        /** Extra controls on the left of the toolbar row. */
        toolbar?: () => unknown;
        /** Shown under the `empty` state only — "create the first one". */
        'empty-action'?: () => unknown;
    } & {
        [slot: `cell-${string}`]: (props: { row: T; value: unknown; column: ColumnDef<T> }) => unknown;
    }
>();

const page = usePage();

/* ---------------------------------------------------------------- columns */

const ACTIONS_KEY = '__actions';

const hasRowActions = computed(() => 'row-actions' in slots);

/** A screen may place the ⋯ menu itself with `cell: 'actions'`; otherwise it is appended. */
const allColumns = computed<ColumnDef<T>[]>(() => {
    if (!hasRowActions.value || props.columns.some((column) => column.cell === 'actions')) {
        return props.columns;
    }

    return [
        ...props.columns,
        {
            key: ACTIONS_KEY,
            header: 'Actions',
            cell: 'actions',
            align: 'right',
            hideable: false,
            headerHidden: true,
            nowrap: true,
        },
    ];
});

const hidden = ref<string[]>(
    readHiddenColumns(props.id) ??
        props.columns.filter((column) => column.defaultHidden === true).map((column) => column.key),
);

function toggleColumn(key: string, visible: boolean): void {
    hidden.value = visible ? hidden.value.filter((entry) => entry !== key) : [...hidden.value, key];
    writeHiddenColumns(props.id, hidden.value);
}

const visibleColumns = computed(() => allColumns.value.filter((column) => !hidden.value.includes(column.key)));

/** Below `md` the ⋯ menu sits at the right of the card, not in the value list. */
const cardColumns = computed(() => visibleColumns.value.filter((column) => column.cell !== 'actions'));
const primaryColumn = computed<ColumnDef<T> | undefined>(() => cardColumns.value[0]);
const secondaryColumns = computed(() => cardColumns.value.slice(1));

/** How wide a full-width row (a group header, an empty-group note) has to span. */
const columnCount = computed(() => visibleColumns.value.length + (props.selectable ? 1 : 0));

/* ----------------------------------------------------------------- groups */

/**
 * How much of a grouped list opens on arrival.
 *
 * Eight status groups expanded is a wall of rows nobody asked for, and eight collapsed
 * headers is a list that shows no list. So groups open from the top until roughly a
 * screenful of rows is already open, and the rest arrive collapsed with their count on the
 * header — one keystroke away, and legible before you press it. 15 is that screenful at
 * 1280×900 once the shell, the page head and the filter bar have taken their share.
 */
const GROUP_OPEN_ROW_BUDGET = 15;

const grouped = computed(() => props.groups !== undefined);

/**
 * Every row once, in render order.
 *
 * A grouping may legitimately place one row in two groups — a task with two assignees is
 * both people's work — so selection, the header checkbox and the empty test all work off
 * the de-duplicated list rather than off a sum of group lengths.
 */
const flatRows = computed<T[]>(() => {
    if (!grouped.value) {
        return props.rows;
    }

    const seen = new Set<string | number>();
    const out: T[] = [];

    for (const group of props.groups ?? []) {
        for (const row of group.rows) {
            if (!seen.has(row.id)) {
                seen.add(row.id);
                out.push(row);
            }
        }
    }

    return out;
});

const isEmpty = computed(() => flatRows.value.length === 0);

/** One unnamed group when ungrouped, so the row markup below is written once. */
const renderGroups = computed<TableGroup<T>[]>(() =>
    grouped.value ? (props.groups ?? []) : [{ key: '__all', label: '', rows: props.rows }],
);

const groupScope = computed(() => props.groupBy ?? 'default');

/** Only the groups this viewer has actually toggled. Everything else follows the rule. */
const groupState = ref<Record<string, boolean>>(readGroupState(props.id, groupScope.value));

watch(groupScope, (scope) => {
    groupState.value = readGroupState(props.id, scope);
});

const defaultOpen = computed<Record<string, boolean>>(() => {
    const state: Record<string, boolean> = {};
    let opened = 0;

    for (const group of renderGroups.value) {
        // An empty group never opens: there is nothing behind the header to read.
        const open = group.rows.length > 0 && opened < GROUP_OPEN_ROW_BUDGET;

        state[group.key] = open;

        if (open) {
            opened += group.rows.length;
        }
    }

    return state;
});

function isGroupOpen(group: TableGroup<T>): boolean {
    return groupState.value[group.key] ?? defaultOpen.value[group.key] ?? true;
}

function toggleGroup(group: TableGroup<T>): void {
    groupState.value = { ...groupState.value, [group.key]: !isGroupOpen(group) };
    writeGroupState(props.id, groupScope.value, groupState.value);
}

function groupCount(group: TableGroup<T>): number {
    return group.count ?? group.rows.length;
}

/* ---------------------------------------------------------------- density */

const density = ref<Density>(readDensity(props.id) ?? props.density ?? 'comfortable');

function setDensity(value: unknown): void {
    if (value !== 'comfortable' && value !== 'compact') {
        return;
    }

    density.value = value;
    writeDensity(props.id, value);
}

/**
 * Density is vertical only. The horizontal padding stays at `ui/table`'s own `px-2`:
 * a wide list already has to scroll sideways at `md`, and widening every cell by 8 px
 * pushes another column past the edge for nothing.
 */
const cellPad = computed(() => (density.value === 'compact' ? 'px-2 py-1.5' : 'px-2 py-3'));

/* ------------------------------------------------------------------- sort */

/** Read off `page.url`, so the back button and a server redirect both land correctly. */
const sort = computed<SortState | null>(() => sortFrom(page.url));

function toggleSort(key: string): void {
    const dir = sort.value?.key === key && sort.value.dir === 'asc' ? 'desc' : 'asc';

    emit('sort-change', { key, dir });
    pushQuery({ sort: key, dir });
}

function ariaSort(column: ColumnDef<T>): 'ascending' | 'descending' | 'none' | undefined {
    if (column.sortable !== true) {
        return undefined;
    }

    if (sort.value?.key !== column.key) {
        return 'none';
    }

    return sort.value.dir === 'asc' ? 'ascending' : 'descending';
}

function sortIcon(column: ColumnDef<T>): Component {
    if (sort.value?.key !== column.key) {
        return ChevronsUpDown;
    }

    return sort.value.dir === 'asc' ? ArrowUp : ArrowDown;
}

/* -------------------------------------------------------------- selection */

const selected = ref<(string | number)[]>([]);

/** A new page of rows is a new selection; keeping ids the viewer can no longer see lies. */
watch(
    () => [props.rows, props.groups],
    () => {
        if (selected.value.length > 0) {
            selected.value = [];
        }
    },
);

watch(selected, (value) => emit('update:selected', [...value]), { deep: true });

function isSelected(row: T): boolean {
    return selected.value.includes(row.id);
}

function toggleRow(row: T, value: unknown): void {
    selected.value = value === true ? [...selected.value, row.id] : selected.value.filter((id) => id !== row.id);
}

const headerChecked = computed<boolean | 'indeterminate'>(() => {
    if (isEmpty.value || selected.value.length === 0) {
        return false;
    }

    return selected.value.length === flatRows.value.length ? true : 'indeterminate';
});

function toggleAll(value: unknown): void {
    selected.value = value === true ? flatRows.value.map((row) => row.id) : [];
}

/* ----------------------------------------------------------- rows per page */

const perPageChoices = computed(() => (props.perPageOptions ?? [10, 15, 25, 50]).map(String));

const perPage = computed({
    get: () => String(props.meta?.per_page ?? perPageChoices.value[0]),
    set: (value: string) => {
        emit('per-page-change', Number(value));
        pushQuery({ per_page: value });
    },
});

/* ------------------------------------------------------------------ cells */

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });
const MONEY = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const COUNT = new Intl.NumberFormat('en-US');

function rawValue(row: T, column: ColumnDef<T>): unknown {
    if (column.value) {
        return column.value(row);
    }

    return (row as unknown as Record<string, unknown>)[column.key];
}

/** The fallback rendering for a column with no `#cell-<key>` slot. */
function display(row: T, column: ColumnDef<T>): string {
    const value = rawValue(row, column);

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (column.cell === 'date') {
        const date = new Date(String(value));

        return Number.isNaN(date.getTime()) ? String(value) : DATE.format(date);
    }

    if (column.cell === 'currency' || column.cell === 'number') {
        const amount = Number(value);

        if (!Number.isFinite(amount)) {
            return String(value);
        }

        return column.cell === 'currency' ? MONEY.format(amount) : COUNT.format(amount);
    }

    return String(value);
}

/** Two letters, so an avatar cell has something to show before an image exists. */
function initials(text: string): string {
    return text
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('');
}

function cellClass(column: ColumnDef<T>): string {
    return cn(
        cellPad.value,
        ALIGN_TEXT[alignOf(column)],
        isNumericCell(column.cell) && 'tabular-nums',
        column.nowrap === true ? 'whitespace-nowrap' : 'whitespace-normal',
        column.width,
    );
}

function labelOf(row: T): string {
    return props.rowLabel ? props.rowLabel(row) : String(row.id);
}

/* ------------------------------------------------------------------ events */

/** A click on a link, a button or a checkbox inside the row is that control's, not the row's. */
function onRowClick(row: T, event: MouseEvent): void {
    const target = event.target as HTMLElement | null;

    if (target?.closest('a, button, input, [role="checkbox"], [role="menuitem"]')) {
        return;
    }

    emit('row-click', row);
}

const toolbarVisible = computed(() => props.viewOptions || props.showPerPage || 'toolbar' in slots);
const headerClass = 'text-xs font-medium uppercase text-muted-foreground';
const stickyClass = 'sticky top-0 z-10 bg-card';
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <div v-if="toolbarVisible" class="flex min-w-0 flex-wrap items-center justify-between gap-2">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <slot name="toolbar" />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div v-if="showPerPage" class="flex items-center gap-2">
                    <Label :for="`${id}-per-page`" class="text-xs font-normal text-muted-foreground">
                        Rows
                    </Label>
                    <Select v-model="perPage">
                        <SelectTrigger :id="`${id}-per-page`" size="sm" class="w-20" aria-label="Rows per page">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="choice in perPageChoices" :key="choice" :value="choice">
                                {{ choice }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <template v-if="viewOptions">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        :model-value="density"
                        aria-label="Row density"
                        @update:model-value="setDensity"
                    >
                        <ToggleGroupItem value="comfortable" aria-label="Comfortable rows">
                            <Rows2 aria-hidden="true" />
                        </ToggleGroupItem>
                        <ToggleGroupItem value="compact" aria-label="Compact rows">
                            <Rows3 aria-hidden="true" />
                        </ToggleGroupItem>
                    </ToggleGroup>

                    <DataTableColumnMenu
                        :columns="allColumns"
                        :hidden="hidden"
                        @toggle="toggleColumn"
                    />
                </template>
            </div>
        </div>

        <DataTableBulkBar
            v-if="selectable && selected.length > 0"
            :count="selected.length"
            :noun="noun"
            @clear="selected = []"
        >
            <slot name="bulk-actions" :selected="selected" />
        </DataTableBulkBar>

        <SkeletonTable v-if="loading" :columns="Math.max(visibleColumns.length, 2)" />

        <Card v-else class="min-w-0 gap-0 overflow-hidden py-0 shadow-xs">
            <CardContent class="px-0">
                <EmptyState
                    v-if="isEmpty"
                    :icon="emptyIcon ?? Inbox"
                    :variant="filtersActive ? 'filtered' : 'empty'"
                    :title="filtersActive ? filteredTitle : emptyTitle"
                    :description="filtersActive ? filteredDescription : emptyDescription"
                    @clear="emit('clear')"
                >
                    <template v-if="!filtersActive && $slots['empty-action']" #action>
                        <slot name="empty-action" />
                    </template>
                </EmptyState>

                <template v-else>
                    <!-- Below md: the same column defs, stacked. No table, so no page overflow. -->
                    <ul class="divide-y md:hidden">
                        <template v-for="group in renderGroups" :key="group.key">
                            <li v-if="grouped" class="bg-muted">
                                <button
                                    type="button"
                                    class="flex w-full min-w-0 items-center gap-2 rounded-md px-4 py-2.5 text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                                    :aria-expanded="isGroupOpen(group)"
                                    @click="toggleGroup(group)"
                                >
                                    <ChevronRight
                                        :class="
                                            cn(
                                                'size-4 shrink-0 text-muted-foreground transition-transform',
                                                isGroupOpen(group) && 'rotate-90',
                                            )
                                        "
                                        aria-hidden="true"
                                    />
                                    <StatusBadge
                                        v-if="group.tone"
                                        :status="group.tone"
                                        :label="group.label"
                                        size="sm"
                                    />
                                    <span v-else class="min-w-0 text-sm font-medium break-words">
                                        {{ group.label }}
                                    </span>
                                    <span class="shrink-0 text-xs text-muted-foreground tabular-nums">
                                        {{ groupCount(group) }}
                                    </span>
                                </button>
                            </li>

                            <template v-if="isGroupOpen(group)">
                                <li
                                    v-if="grouped && group.rows.length === 0"
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    Nothing in this group.
                                </li>

                                <!--
                                    The card list carries the same row affordance as the
                                    table: `rowClickable` has to mean the same thing below
                                    `md`, or the drawer a list opens on a row is unreachable
                                    on a phone. Same guard, same keyboard stop, same emit.
                                -->
                                <li
                                    v-for="row in group.rows"
                                    :key="row.id"
                                    :class="cn('flex items-start gap-3 p-4', rowClickable && 'cursor-pointer')"
                                    :tabindex="rowClickable ? 0 : undefined"
                                    :role="rowClickable ? 'button' : undefined"
                                    :aria-label="rowClickable ? labelOf(row) : undefined"
                                    @click="(event: MouseEvent) => rowClickable && onRowClick(row, event)"
                                    @keydown.enter="rowClickable && emit('row-click', row)"
                                >
                                    <slot name="card" :row="row">
                                        <Checkbox
                                            v-if="selectable"
                                            class="mt-1 shrink-0"
                                            :model-value="isSelected(row)"
                                            :aria-label="`Select ${labelOf(row)}`"
                                            @update:model-value="(value) => toggleRow(row, value)"
                                        />

                                        <div class="flex min-w-0 flex-1 flex-col gap-2">
                                            <div
                                                v-if="primaryColumn"
                                                class="min-w-0 text-sm font-medium break-words"
                                            >
                                                <slot
                                                    :name="`cell-${primaryColumn.key}`"
                                                    :row="row"
                                                    :value="rawValue(row, primaryColumn)"
                                                    :column="primaryColumn"
                                                >
                                                    {{ display(row, primaryColumn) }}
                                                </slot>
                                            </div>

                                            <dl class="flex min-w-0 flex-col gap-1">
                                                <div
                                                    v-for="column in secondaryColumns"
                                                    :key="column.key"
                                                    class="flex min-w-0 flex-wrap items-baseline gap-2"
                                                >
                                                    <dt class="shrink-0 text-xs text-muted-foreground">
                                                        {{ column.header }}
                                                    </dt>
                                                    <dd
                                                        :class="
                                                            cn(
                                                                'min-w-0 text-xs break-words',
                                                                isNumericCell(column.cell) && 'tabular-nums',
                                                            )
                                                        "
                                                    >
                                                        <slot
                                                            :name="`cell-${column.key}`"
                                                            :row="row"
                                                            :value="rawValue(row, column)"
                                                            :column="column"
                                                        >
                                                            {{ display(row, column) }}
                                                        </slot>
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <DropdownMenu v-if="hasRowActions">
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    class="shrink-0"
                                                    :aria-label="`Actions for ${labelOf(row)}`"
                                                >
                                                    <MoreHorizontal aria-hidden="true" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <slot name="row-actions" :row="row" />
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </slot>
                                </li>
                            </template>
                        </template>
                    </ul>

                    <!-- md and up: the table. Only this box scrolls sideways, never the page. -->
                    <div class="hidden min-w-0 md:block">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead v-if="selectable" :class="cn(headerClass, stickyClass, 'w-10 px-2')">
                                        <Checkbox
                                            :model-value="headerChecked"
                                            aria-label="Select all rows on this page"
                                            @update:model-value="toggleAll"
                                        />
                                    </TableHead>
                                    <TableHead
                                        v-for="column in visibleColumns"
                                        :key="column.key"
                                        :class="
                                            cn(
                                                headerClass,
                                                stickyClass,
                                                'px-2',
                                                ALIGN_TEXT[alignOf(column)],
                                                column.width,
                                            )
                                        "
                                        :aria-sort="ariaSort(column)"
                                    >
                                        <span v-if="column.headerHidden" class="sr-only">{{ column.header }}</span>
                                        <button
                                            v-else-if="column.sortable"
                                            type="button"
                                            :class="
                                                cn(
                                                    'inline-flex w-full items-center gap-1 rounded-md outline-none hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50',
                                                    ALIGN_JUSTIFY[alignOf(column)],
                                                )
                                            "
                                            @click="toggleSort(column.key)"
                                        >
                                            {{ column.header }}
                                            <component :is="sortIcon(column)" class="size-3" aria-hidden="true" />
                                        </button>
                                        <span v-else>{{ column.header }}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <template v-for="group in renderGroups" :key="group.key">
                                    <!--
                                        The group band. A <button> rather than a clickable row,
                                        so Tab reaches it and Enter/Space toggle it for free.
                                    -->
                                    <TableRow v-if="grouped" class="hover:bg-transparent">
                                        <!--
                                            The cell has no padding of its own: the button
                                            carries it, so the whole band is the target rather
                                            than an 18 px strip of text inside it.
                                        -->
                                        <TableCell :colspan="columnCount" class="bg-muted p-0">
                                            <button
                                                type="button"
                                                class="flex w-full min-w-0 items-center gap-2 rounded-md px-2 py-2 text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                                                :aria-expanded="isGroupOpen(group)"
                                                @click="toggleGroup(group)"
                                            >
                                                <ChevronRight
                                                    :class="
                                                        cn(
                                                            'size-4 shrink-0 text-muted-foreground transition-transform',
                                                            isGroupOpen(group) && 'rotate-90',
                                                        )
                                                    "
                                                    aria-hidden="true"
                                                />
                                                <StatusBadge
                                                    v-if="group.tone"
                                                    :status="group.tone"
                                                    :label="group.label"
                                                    size="sm"
                                                />
                                                <span v-else class="min-w-0 text-sm font-medium break-words">
                                                    {{ group.label }}
                                                </span>
                                                <span
                                                    class="shrink-0 text-xs text-muted-foreground tabular-nums"
                                                >
                                                    {{ groupCount(group) }}
                                                </span>
                                            </button>
                                        </TableCell>
                                    </TableRow>

                                    <template v-if="isGroupOpen(group)">
                                        <TableRow v-if="grouped && group.rows.length === 0">
                                            <TableCell
                                                :colspan="columnCount"
                                                class="px-2 py-3 text-xs text-muted-foreground"
                                            >
                                                Nothing in this group.
                                            </TableCell>
                                        </TableRow>

                                        <!--
                                            Striping is counted inside the group, not with
                                            `even:`: nth-child would count the header bands too
                                            and the zebra would restart at a different parity in
                                            every group.
                                        -->
                                        <TableRow
                                            v-for="(row, index) in group.rows"
                                            :key="row.id"
                                            :class="
                                                cn(
                                                    index % 2 === 1 && 'bg-muted/40',
                                                    rowClickable && 'cursor-pointer',
                                                )
                                            "
                                            :data-state="isSelected(row) ? 'selected' : undefined"
                                            :tabindex="rowClickable ? 0 : undefined"
                                            @click="(event: MouseEvent) => onRowClick(row, event)"
                                            @keydown.enter="rowClickable && emit('row-click', row)"
                                        >
                                            <TableCell v-if="selectable" :class="cn(cellPad, 'w-10')">
                                                <Checkbox
                                                    :model-value="isSelected(row)"
                                                    :aria-label="`Select ${labelOf(row)}`"
                                                    @update:model-value="(value) => toggleRow(row, value)"
                                                />
                                            </TableCell>

                                            <TableCell
                                                v-for="column in visibleColumns"
                                                :key="column.key"
                                                :class="cellClass(column)"
                                            >
                                                <template v-if="column.cell === 'actions'">
                                                    <DropdownMenu v-if="hasRowActions">
                                                        <DropdownMenuTrigger as-child>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                :aria-label="`Actions for ${labelOf(row)}`"
                                                            >
                                                                <MoreHorizontal aria-hidden="true" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <slot name="row-actions" :row="row" />
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </template>

                                                <slot
                                                    v-else
                                                    :name="`cell-${column.key}`"
                                                    :row="row"
                                                    :value="rawValue(row, column)"
                                                    :column="column"
                                                >
                                                    <Badge
                                                        v-if="column.cell === 'badge'"
                                                        variant="secondary"
                                                    >
                                                        {{ display(row, column) }}
                                                    </Badge>
                                                    <span
                                                        v-else-if="column.cell === 'avatar'"
                                                        class="inline-flex items-center gap-2"
                                                    >
                                                        <Avatar class="size-6">
                                                            <AvatarFallback class="text-xs">
                                                                {{ initials(display(row, column)) }}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        {{ display(row, column) }}
                                                    </span>
                                                    <template v-else>{{ display(row, column) }}</template>
                                                </slot>
                                            </TableCell>
                                        </TableRow>
                                    </template>
                                </template>
                            </TableBody>
                        </Table>
                    </div>
                </template>
            </CardContent>
        </Card>

        <Pagination v-if="meta && links" :links="links" :meta="meta" />
    </div>
</template>
