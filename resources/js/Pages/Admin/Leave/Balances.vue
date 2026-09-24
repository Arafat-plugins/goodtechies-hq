<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, ListChecks, Pencil, Users } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import EditBalanceDialog from '@/Components/Leave/EditBalanceDialog.vue';
import type { LeaveBalanceGridRow, LeavePerson, LeaveTypeOption } from '@/Components/Leave/leave';
import { leaveRoutes } from '@/Components/Leave/leave';
import PageShell from '@/Components/PageShell.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

/**
 * Admin → Workforce → Leave → Balances: how many days of each capped type each person has left.
 *
 * ## Nothing here is sortable by a number, and that is deliberate
 *
 * People are ordered by name and by nothing else. A table of colleagues ordered by how much
 * leave they have left is a ranking of people, which Part H §1 forbids — the same reason the
 * Workload screen's estimated-vs-tracked columns are never divided, subtracted or sorted
 * (decision 4-23). These are four numbers per person and they are not compared with anybody.
 *
 * ## Only the capped types have a column
 *
 * Unpaid and Other have no balance (Part D §9), so there is no cell for them rather than a cell
 * reading 0 — a control or a number the server ignores is a lie in the UI (DESIGN.md §5.11).
 * The uncapped types are named in a line under the table instead.
 *
 * ## Below `md` it is a card per person
 *
 * Four type columns plus a name plus an edit control is six columns; at 360 px that is about
 * 40 px each, and "Emergency" does not fit. The same switch `DataTable` makes at the same
 * breakpoint, for the same reason decision 4-13 gives.
 *
 * `can_edit` is `LeaveRequestPolicy::manageBalances` resolved per row on the server, so no edit
 * control is drawn on somebody the endpoint would refuse (decisions 2-28, 2-31).
 */

defineProps<{
    types: LeaveTypeOption[];
    rows: LeaveBalanceGridRow[];
}>();

const editing = ref<{ employee: LeavePerson; type: LeaveTypeOption; current: number } | null>(null);
const dialogOpen = ref(false);

function edit(employee: LeavePerson, type: LeaveTypeOption, current: number): void {
    editing.value = { employee, type, current };
    dialogOpen.value = true;
}

function balanceOf(row: LeaveBalanceGridRow, type: LeaveTypeOption): number {
    return row.balances.find((balance) => balance.leave_type_id === type.id)?.balance_days ?? 0;
}
</script>

<template>
    <Head title="Leave balances" />

    <PageShell
        title="Leave balances"
        description="Days left per person, per type. Nothing tops these up — an Admin sets them."
        :breadcrumb="[{ label: 'Workforce' }, { label: 'Leave', href: leaveRoutes.queue() }, { label: 'Balances' }]"
    >
        <template #actions>
            <div class="flex flex-wrap items-center gap-2">
                <Button as-child variant="outline">
                    <Link :href="leaveRoutes.queue()">
                        <ListChecks class="size-4" aria-hidden="true" />
                        Queue
                    </Link>
                </Button>
                <Button as-child variant="outline">
                    <Link :href="leaveRoutes.calendar()">
                        <CalendarDays class="size-4" aria-hidden="true" />
                        Calendar
                    </Link>
                </Button>
            </div>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
            <template v-if="rows.length">
                <!-- Below md: one card per person. -->
                <ul class="flex flex-col gap-3 md:hidden">
                    <li v-for="row in rows" :key="row.employee.id">
                        <Card class="flex flex-col gap-3 p-4">
                            <div class="flex flex-col gap-0.5">
                                <p class="text-sm font-semibold">{{ row.employee.name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.employee.role ?? '—' }} · {{ row.employee.employee_number ?? '—' }}
                                </p>
                            </div>

                            <ul class="flex flex-col gap-2">
                                <li
                                    v-for="type in types"
                                    :key="type.id"
                                    class="flex items-center justify-between gap-2 border-t pt-2 first:border-t-0 first:pt-0"
                                >
                                    <span class="text-sm">{{ type.name }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="text-sm font-medium tabular-nums">
                                            {{ balanceOf(row, type) }}
                                            <span class="text-xs font-normal text-muted-foreground">
                                                {{ balanceOf(row, type) === 1 ? 'day' : 'days' }}
                                            </span>
                                        </span>
                                        <Button
                                            v-if="row.can_edit"
                                            variant="ghost"
                                            size="icon"
                                            @click="edit(row.employee, type, balanceOf(row, type))"
                                        >
                                            <Pencil class="size-4" aria-hidden="true" />
                                            <span class="sr-only">
                                                Set {{ row.employee.name }}'s {{ type.name }} balance
                                            </span>
                                        </Button>
                                    </span>
                                </li>
                            </ul>
                        </Card>
                    </li>
                </ul>

                <!-- md and up: the grid. -->
                <Card class="hidden min-w-0 overflow-x-auto md:block">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            Leave balances in days, per person and per type. Ordered by name.
                        </caption>
                        <thead>
                            <tr class="border-b text-left">
                                <th scope="col" class="px-4 py-3 font-medium">Person</th>
                                <th
                                    v-for="type in types"
                                    :key="type.id"
                                    scope="col"
                                    class="px-4 py-3 text-right font-medium"
                                >
                                    {{ type.name }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.employee.id" class="border-b last:border-b-0">
                                <th scope="row" class="px-4 py-3 text-left font-normal">
                                    <span class="font-medium">{{ row.employee.name }}</span>
                                    <span class="block text-xs text-muted-foreground">
                                        {{ row.employee.role ?? '—' }}
                                    </span>
                                </th>
                                <td v-for="type in types" :key="type.id" class="px-4 py-3 text-right">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        <span class="tabular-nums">{{ balanceOf(row, type) }}</span>
                                        <Button
                                            v-if="row.can_edit"
                                            variant="ghost"
                                            size="icon"
                                            class="size-7"
                                            @click="edit(row.employee, type, balanceOf(row, type))"
                                        >
                                            <Pencil class="size-3.5" aria-hidden="true" />
                                            <span class="sr-only">
                                                Set {{ row.employee.name }}'s {{ type.name }} balance
                                            </span>
                                        </Button>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </Card>

                <p class="text-xs text-muted-foreground">
                    Unpaid and Other have no balance and no column — they are never refused for want of days
                    (Part D §9). Numbers are days, and nothing here ranks one person against another.
                </p>
            </template>

            <Card v-else class="p-4">
                <EmptyState
                    :icon="Users"
                    title="Nobody to show"
                    description="There is no employee whose leave you manage."
                />
            </Card>
        </div>

        <EditBalanceDialog
            v-model:open="dialogOpen"
            :employee="editing?.employee ?? null"
            :type="editing?.type ?? null"
            :current="editing?.current ?? 0"
        />
    </PageShell>
</template>
