<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { KeyRound, Plus, UserCheck, UserRound, UserX } from '@lucide/vue';
import { computed, ref } from 'vue';
import DataTable from '@/Components/DataTable/DataTable.vue';
import type { ColumnDef } from '@/Components/DataTable/types';
import EmployeeCreateDialog from '@/Components/Employees/EmployeeCreateDialog.vue';
import EmployeeStatusDialog from '@/Components/Employees/EmployeeStatusDialog.vue';
import type { EmployeeFormOptions, EmployeeRow } from '@/Components/Employees/employees';
import {
    employeeUrl,
    roleLabel,
    scheduleSummary,
    statusLabel,
    statusTone,
    trackingModeLabel,
} from '@/Components/Employees/employees';
import type { FilterDef } from '@/Components/FilterBar.vue';
import FilterBar from '@/Components/FilterBar.vue';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Button } from '@/Components/ui/button';
import { DropdownMenuItem } from '@/Components/ui/dropdown-menu';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useMenuDialog } from '@/lib/menuFocus';
import { queryOf, resetQuery } from '@/lib/tableState';
import { useNavigationPending } from '@/lib/useNavigationPending';

defineOptions({ layout: AdminLayout });

/**
 * Admin → Workforce → **Employees**, and Admin → **Users & Roles**.
 *
 * Part D §2 settles the shape in one line: *"Users & Roles is the same screen family
 * (Employees list → employee detail → role/schedule/tracking_mode)"*. So this is **one list
 * asked two questions**, not two screens. `?view=access` switches the columns from the
 * workforce set (how somebody's time is measured, which week they work) to the access set
 * (what they may reach, and what has been granted to them on top of it) — the same deep-link
 * trick My Tasks already uses for Due Today and Overdue, and `activeItem()` lights whichever
 * of the two nav rows named the longer claim.
 *
 * ## Inactive people are on this list
 *
 * Part B §3 rule 11: *"Employee departure = `status = inactive`, never delete."* A roster that
 * hid them would make "where did Yaseen go" unanswerable, and the tasks, hours and payslips
 * with their name on stay in the system either way. They are marked with the WORD *Inactive*,
 * never with a grey row: colour alone is not a state (DESIGN.md §5.6).
 *
 * ## The row menu can deactivate because the row carries the consequence
 *
 * `EmployeeResource` puts `impact.open_task_count` and `permissions` on every row, not only on
 * the detail — so the confirmation opened from here says the same number, and links to the same
 * tasks, as the one opened from the person's own page. Without that the action would have had
 * to fall back to "are you sure", which is the thing this slice exists to avoid.
 *
 * ## Nothing here is a permission check, and nothing here is money
 *
 * The server sends what this viewer may see and what they may do; this renders it. There is no
 * salary column, field or placeholder — salary is `/salaries` (Phase 9) with its own key.
 */

const props = defineProps<{
    /**
     * The whole roster, not a page of it: `EmployeeController::index()` resolves the resource
     * collection flat and orders by name, so there is no paginator to pass on and no
     * rows-per-page control to draw (DESIGN.md §5.11).
     */
    employees: EmployeeRow[];
    /**
     * Echoed back by the controller, which is also how this page knows which filters the
     * server actually reads: a chip is offered only for a key that came back. Today that is
     * **search and status** — there is no role or tracking-mode chip because the controller
     * reads neither, and a chip that narrows nothing is a lie in the UI.
     */
    filters: {
        search?: string | null;
        /** `'all'` is the controller's sentinel for *not narrowed*, not a filter value. */
        status?: string | null;
    };
    /**
     * The create form's selects. **Null** — which is what the controller sends a viewer who may
     * not create — means no create control is drawn at all.
     */
    options?: EmployeeFormOptions | null;
}>();

const page = usePage();

/* -------------------------------------------------------------------- view */

/**
 * Which question the list is being asked. It lives in the query string because it is
 * shareable, and it is read from `page.url` so a nav click re-renders it.
 */
const view = computed<'workforce' | 'access'>(() => (queryOf(page.url).view === 'access' ? 'access' : 'workforce'));

const heading = computed(() =>
    view.value === 'access'
        ? {
              title: 'Users & roles',
              description:
                  'Who has an account here, the role it carries, and anything granted on top of it. Inactive people stay on the list.',
              breadcrumb: [{ label: 'Admin' }, { label: 'Users & roles' }],
          }
        : {
              title: 'Employees',
              description:
                  'Everyone who works here, how their time is measured and which week they work. Inactive people stay on the list.',
              breadcrumb: [{ label: 'Workforce' }, { label: 'Employees' }],
          },
);

/* ----------------------------------------------------------------- filters */

/**
 * A chip exists only for a key the controller echoed back. Removing the Status chip *is* "every
 * status", which is why there is no "All" option in it — the controller's `'all'` is the
 * absence of the parameter, not a value anybody picks.
 */
const filterDefs = computed<FilterDef[]>(() =>
    'status' in props.filters
        ? [
              {
                  key: 'status',
                  label: 'Status',
                  kind: 'select',
                  options: [
                      { value: 'active', label: 'Active' },
                      { value: 'inactive', label: 'Inactive' },
                  ],
              },
          ]
        : [],
);

/** `'all'` is the controller's word for *not narrowed*, so it is not an active filter. */
const narrowedStatus = computed(() => {
    const status = props.filters.status;

    return status && status !== 'all' ? status : null;
});

const hasFilters = computed(() => Boolean(props.filters.search) || narrowedStatus.value !== null);

/**
 * `FilterBar`'s chip-mode *Clear all* calls `resetQuery()` with no `keep`, which would take
 * `?view=access` with it and quietly move the reader to the other question. Re-applying it
 * here is the workaround; `FilterBar` taking a `keep` list is the fix (reported, not made —
 * it is a shared component).
 */
function clearFilters(): void {
    resetQuery(view.value === 'access' ? ['view'] : []);
}

/* ----------------------------------------------------------------- columns */

/**
 * No column is `sortable` and there is no rows-per-page control until the controller reads
 * `sort`, `dir` and `per_page` (decisions 0.5-16, 0.5-19).
 */
const columns = computed<ColumnDef<EmployeeRow>[]>(() => {
    const common: ColumnDef<EmployeeRow>[] = [
        { key: 'name', header: 'Name', hideable: false },
        { key: 'role', header: 'Role', nowrap: true, value: (employee) => roleLabel(employee) },
    ];

    const tail: ColumnDef<EmployeeRow>[] = [{ key: 'status', header: 'Status', cell: 'badge', nowrap: true }];

    if (view.value === 'access') {
        return [
            ...common,
            {
                key: 'project_permissions_count',
                header: 'Project grants',
                cell: 'number',
                nowrap: true,
                value: (employee) => employee.project_permissions_count ?? 0,
            },
            ...tail,
        ];
    }

    return [
        ...common,
        {
            key: 'tracking_mode',
            header: 'Tracking mode',
            nowrap: true,
            value: (employee) => trackingModeLabel(employee),
        },
        {
            key: 'schedule_summary',
            header: 'Working week',
            value: (employee) => scheduleSummary(employee, props.options?.weekdays ?? []) ?? 'No schedule set',
        },
        ...tail,
    ];
});

const loading = useNavigationPending();

/* ----------------------------------------------------------------- actions */

const creating = ref(false);

const pending = ref<EmployeeRow | null>(null);
const pendingIntent = ref<'deactivate' | 'reactivate'>('deactivate');

/**
 * Decision 5-20: the status dialog is opened from a `DropdownMenuItem` the `⋯` menu unmounts on
 * select, so reka's own focus restore landed on `<body>`. `openFromMenu` captures the row's `⋯`
 * trigger and defers the dialog by a tick, and `closeStatus` is the one way out for focus — the row
 * survives a deactivation (it changes its badge), so the trigger is there to go back to.
 * See `lib/menuFocus.ts`.
 */
const menu = useMenuDialog();

function ask(employee: EmployeeRow, intent: 'deactivate' | 'reactivate'): void {
    menu.openFromMenu(() => {
        pendingIntent.value = intent;
        pending.value = employee;
    });
}

function closeStatus(): void {
    pending.value = null;
    menu.returnFocus();
}
</script>

<template>
    <Head :title="heading.title" />

    <PageShell :title="heading.title" :description="heading.description" :breadcrumb="heading.breadcrumb">
        <template v-if="options" #actions>
            <Button type="button" @click="creating = true">
                <Plus aria-hidden="true" />
                New employee
            </Button>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <FilterBar
                page-actions
                :search="filters.search ?? null"
                :filters="filterDefs"
                placeholder="Search employees…"
                input-id="employees-search"
                @clear="clearFilters"
            />

            <DataTable
                id="admin-employees"
                :columns="columns"
                :rows="employees"
                :loading="loading"
                :filters-active="hasFilters"
                :row-label="(employee) => employee.name"
                noun="employee"
                :empty-icon="UserRound"
                empty-title="Nobody here yet"
                empty-description="Add the first employee and they can be given work."
                filtered-title="No employees match these filters"
                filtered-description="Clear a filter, or widen the search."
                @clear="clearFilters"
            >
                <template #cell-name="{ row }">
                    <Link :href="employeeUrl(row)" class="font-medium break-words hover:underline">
                        {{ row.name }}
                    </Link>
                    <span v-if="row.employee_number" class="ml-2 text-xs text-muted-foreground">
                        {{ row.employee_number }}
                    </span>
                </template>

                <template #cell-role="{ row }">{{ roleLabel(row) }}</template>

                <template #cell-tracking_mode="{ row }">{{ trackingModeLabel(row) }}</template>

                <template #cell-schedule_summary="{ row }">
                    {{ scheduleSummary(row, options?.weekdays ?? []) ?? 'No schedule set' }}
                </template>

                <!--
                    A count of something openable is a link. It goes to this person's own page,
                    where the grants it counted are listed one by one.
                -->
                <template #cell-project_permissions_count="{ row }">
                    <Link
                        v-if="(row.project_permissions_count ?? 0) > 0"
                        :href="`${employeeUrl(row)}#project-access`"
                        class="font-medium hover:underline"
                    >
                        {{ row.project_permissions_count }}
                        <span class="sr-only">project permission grants for {{ row.name }}</span>
                    </Link>
                    <span v-else class="text-muted-foreground">None</span>
                </template>

                <!-- The word, always — an inactive row that is only grey says nothing. -->
                <template #cell-status="{ row }">
                    <StatusBadge :status="statusTone(row.status)" :label="statusLabel(row)" />
                </template>

                <template #row-actions="{ row }">
                    <DropdownMenuItem as-child>
                        <Link :href="employeeUrl(row)" class="w-full">
                            <UserRound aria-hidden="true" />
                            View
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <Link :href="`${employeeUrl(row)}#project-access`" class="w-full">
                            <KeyRound aria-hidden="true" />
                            Project access
                        </Link>
                    </DropdownMenuItem>
                    <!--
                        Two conditions, and they are different questions. `permissions` is the
                        server's answer to *may this viewer make the move* — absent is no, and
                        the endpoint checks again regardless. `status` is the record's own
                        state, and it is why only one of the two verbs is ever drawn:
                        `EmployeePolicy` answers yes to both for an active employee, so without
                        it the menu offers to reactivate somebody who is already active.
                    -->
                    <DropdownMenuItem
                        v-if="row.status === 'active' && row.permissions?.can_deactivate"
                        variant="destructive"
                        @select="ask(row, 'deactivate')"
                    >
                        <UserX aria-hidden="true" />
                        Deactivate
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="row.status !== 'active' && row.permissions?.can_reactivate"
                        @select="ask(row, 'reactivate')"
                    >
                        <UserCheck aria-hidden="true" />
                        Reactivate
                    </DropdownMenuItem>
                </template>

                <template v-if="options" #empty-action>
                    <Button type="button" @click="creating = true">
                        <Plus aria-hidden="true" />
                        New employee
                    </Button>
                </template>
            </DataTable>
        </div>
    </PageShell>

    <EmployeeCreateDialog v-if="options" v-model:open="creating" :options="options" />

    <EmployeeStatusDialog :employee="pending" :intent="pendingIntent" @close="closeStatus" />
</template>
