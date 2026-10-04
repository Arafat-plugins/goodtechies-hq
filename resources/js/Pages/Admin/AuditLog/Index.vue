<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ScrollText } from '@lucide/vue';
import { computed, ref } from 'vue';
import AuditEntryDrawer from '@/Components/Audit/AuditEntryDrawer.vue';
import type { AuditEntry, AuditFilters, AuditOptions } from '@/Components/Audit/audit';
import { actorName, changeSummary, targetName } from '@/Components/Audit/audit';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import type { FilterDef } from '@/Components/FilterBar.vue';
import FilterBar from '@/Components/FilterBar.vue';
import PageShell from '@/Components/PageShell.vue';
import type { Paginated } from '@/Components/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { resetQuery } from '@/lib/tableState';
import { useNavigationPending } from '@/lib/useNavigationPending';

defineOptions({ layout: AdminLayout });

/**
 * Admin → **Audit Log** (Part E, Phase 12: *"Admin → Audit Log viewer (filters, old/new diff,
 * read-only)"*).
 *
 * Eleven phases have been writing to `audit_logs` — every salary change, every project price,
 * every role change, every payroll lock reversal, every refused read of somebody else's payslip
 * — and until this screen nothing in the application read one back. It is what makes acceptance
 * criterion 11 something a person can check rather than something a test asserts.
 *
 * ## There is nothing to press
 *
 * No create, no edit, no delete, no "correct this entry", and **no export** — spec §44 puts
 * PDF/CSV in post-MVP Phase 2, so there is not a disabled button either (DESIGN.md §5.12: a
 * control that does nothing is worse than no control). The one interaction is opening a row to
 * read its diff. `audit_logs` is append-only at the database and `hq_app` cannot grant itself
 * otherwise (Part B §3 rule 3), so a write control here would be a button whose only outcome is
 * a 500.
 *
 * ## The diff is the feature
 *
 * The list answers *when, who, to what, and roughly what changed*; the drawer answers *which
 * field, from what, to what*. `AuditDiff` is where that reading happens and its docblock says
 * why two JSON blobs side by side are not a diff. Every label on this screen — the event's, the
 * area's, a field's, a target class's — comes from the server, because `AuditEvent` is the only
 * thing that knows what a stored value means.
 *
 * ## Every filter is in the query string
 *
 * Four chips — who, which event, which kind of record, and a date range — and `FilterBar` in
 * chip mode owns the URL, so a narrowed log is a link somebody can paste into a message and a
 * reload lands on the same view. There is **no search box**: the controller reads no search term
 * and the only free-text in this table is inside a `jsonb` column with no index on it, so a box
 * would be a control the server ignores (DESIGN.md §5.11).
 */
const props = defineProps<{
    entries: Paginated<AuditEntry>;
    filters: AuditFilters;
    options: AuditOptions;
    /** The agency's timezone. Every timestamp here is in it, and the date filter cuts on it. */
    timezone: string;
    /**
     * The entry a pasted `?detail=` link names, when the page it also asked for does not contain
     * it. Null on every ordinary visit — a row click reads the row the list already sent.
     */
    entry: AuditEntry | null;
}>();

/* ----------------------------------------------------------------- filters */

/**
 * The chips. Each `key` is a parameter `AuditLogIndexRequest` reads, and no chip is offered for
 * anything else.
 *
 * The event options arrive **grouped by area and ordered that way** — access first, then
 * permissions, people, projects, tasks, time, leave, money, configuration — because thirty-five
 * events in one alphabetical list is a memory test, and "the money ones" should be a run of
 * adjacent rows. They are also only the events that have actually been recorded, so no option
 * in the picker returns an empty log, and an event string from an older build is offered rather
 * than being unaskable.
 */
const filterDefs = computed<FilterDef[]>(() => [
    {
        key: 'actor',
        label: 'Who',
        kind: 'select',
        options: props.options.actors,
        searchPlaceholder: 'Find a person…',
    },
    {
        key: 'event',
        label: 'Event',
        kind: 'select',
        options: props.options.events,
        searchPlaceholder: 'Find an event…',
    },
    {
        key: 'target_type',
        label: 'Record',
        kind: 'select',
        options: props.options.target_types,
        searchPlaceholder: 'Find a kind of record…',
    },
    { key: 'date', label: 'Recorded', kind: 'date-range' },
]);

const hasFilters = computed(() =>
    Object.values(props.filters).some((value) => value !== null && value !== ''),
);

/**
 * `FilterBar`'s own *Clear all* already resets the query string; this drops the open entry with
 * it, because `?detail=` is the one parameter that is not a filter and a cleared view should not
 * keep a drawer open over it.
 */
function clearFilters(): void {
    resetQuery();
}

/* ----------------------------------------------------------------- columns */

/**
 * No column is `sortable` and there is no rows-per-page control: the controller orders by `id`
 * descending and reads neither `sort` nor `per_page` (DESIGN.md §5.11). That ordering is not an
 * omission — on an append-only table whose `created_at` is set by the database, id order *is*
 * chronological order, and it is the only one of the two with an index behind it.
 */
const columns = computed<ColumnDef<AuditEntry>[]>(() => [
    { key: 'recorded_at', header: 'When', hideable: false, nowrap: true },
    { key: 'event_label', header: 'Event', hideable: false },
    // The event's family — Access, Money, Leave … — from `AuditEvent::group()`. It is a column
    // and not a filter: the picker already orders its options by it, so this is what teaches a
    // reader why *Payroll lock reversed* and *Salary changed* sit next to each other there.
    // Hideable, because a reader who knows the events by name does not need it.
    { key: 'event_group', header: 'Area', nowrap: true },
    { key: 'actor', header: 'Who', value: (entry) => actorName(entry) },
    { key: 'target', header: 'Record', value: (entry) => targetName(entry) },
    { key: 'change', header: 'Change', value: (entry) => changeSummary(entry) },
]);

const loading = useNavigationPending();

/* ------------------------------------------------------------------- entry */

/**
 * Which entry the drawer is showing.
 *
 * Seeded from the server's `entry` prop, which is how a pasted `?detail=` link opens — read
 * here in setup and not in `onMounted`, because `DetailDrawer` syncs the URL from a watcher
 * with `immediate: true` and would strip the parameter first.
 */
const selected = ref<AuditEntry | null>(props.entry);
</script>

<template>
    <Head title="Audit log" />

    <PageShell
        title="Audit log"
        :description="`Every recorded change, newest first. It cannot be edited or deleted by anybody, including you — the table is append-only in the database itself. Times are ${timezone}.`"
        :breadcrumb="[{ label: 'Admin' }, { label: 'Audit log' }]"
    >
        <div class="flex min-w-0 flex-col gap-4">
            <FilterBar page-actions :filters="filterDefs" :searchable="false" @clear="clearFilters" />

            <DataTable
                id="admin-audit-log"
                :columns="columns"
                :rows="entries.data"
                :meta="entries.meta"
                :links="entries.links"
                :loading="loading"
                :filters-active="hasFilters"
                :row-label="(entry) => `${entry.event_label}, ${entry.recorded_at ?? ''}`"
                row-clickable
                noun="entry"
                :empty-icon="ScrollText"
                empty-title="Nothing recorded yet"
                empty-description="Entries appear here as soon as something worth recording happens — a role change, a salary, a price, an approval."
                filtered-title="No entries match these filters"
                filtered-description="Widen the date range, or clear a filter."
                @clear="clearFilters"
                @row-click="selected = $event"
            >
                <template #cell-recorded_at="{ row }">
                    <span class="tabular-nums">{{ row.recorded_at ?? 'Unknown' }}</span>
                </template>

                <!--
                    The label, with the stored value under it. The raw string is there because it
                    is what an auditor quotes, and because an event this build has no definition
                    for is shown as recorded rather than hidden — the column has no CHECK behind
                    it, so a row from an earlier version is a valid row.
                -->
                <template #cell-event_label="{ row }">
                    <span class="font-medium break-words">{{ row.event_label }}</span>
                    <span class="block font-mono text-xs break-all text-muted-foreground">{{ row.event }}</span>
                </template>

                <template #cell-event_group="{ row }">{{ row.event_group }}</template>

                <template #cell-actor="{ row }">
                    <span class="break-words">{{ actorName(row) }}</span>
                    <span v-if="row.actor === null" class="block text-xs text-muted-foreground">
                        No signed-in actor
                    </span>
                </template>

                <template #cell-target="{ row }">
                    <span class="break-words">{{ targetName(row) }}</span>
                </template>

                <template #cell-change="{ row }">
                    <span class="break-words">{{ changeSummary(row) }}</span>
                </template>
            </DataTable>
        </div>
    </PageShell>

    <AuditEntryDrawer :entry="selected" :timezone="timezone" @close="selected = null" />
</template>
