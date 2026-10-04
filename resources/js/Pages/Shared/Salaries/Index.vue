<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { History, Users } from '@lucide/vue';
import EmptyState from '@/Components/EmptyState.vue';
import { formatMoney } from '@/Components/Finance/finance';
import type { SalaryEmployee } from '@/Components/Payroll/payslip';
import { formatEffectiveDate } from '@/Components/Payroll/payslip';
import SalaryDeleteButton from '@/Components/Payroll/SalaryDeleteButton.vue';
import SalaryEditDialog from '@/Components/Payroll/SalaryEditDialog.vue';
import PageShell from '@/Components/PageShell.vue';
import { Card } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * **Salary settings** — what each person is paid, from a date (master prompt Part D §14's
 * *"salary settings per employee (base salary, allowances — audit-logged)"*, under **Admin →
 * Payroll**; Phase 9, slice 2b).
 *
 * ## Admin only, and the route says so
 *
 * `/salaries` carries `can:payroll.approve`, which `RolePermissionSeeder` grants to ADMIN and
 * to nobody else — so the Accountant, every Manager, every Employee and every Remote employee
 * get **403** before this file is ever resolved. The layout is still picked from
 * `auth.user.surface`, the way every page under `Pages/Shared/` does it: today that always
 * resolves to `AdminLayout` because only an Admin can arrive, and writing it this way means the
 * shell follows the permission rather than a hard-coded assumption about who holds it.
 *
 * ## This screen is a history, not an edit form, and it says so in four places
 *
 * Decision 9-1: `employee_salaries` is **effective-dated rows**. `setSalary()` writes a new row
 * per change; `salaryFor()` reads the latest row at or before a given day; nothing updates an
 * old row, ever — because the moment one did, September would stop recomputing to September's
 * number.
 *
 * A screen that looked like a settings form with two boxes would teach exactly the wrong model,
 * so this one is built to teach the right one:
 *
 *  1. the page's own description says earlier months keep their figures;
 *  2. each person's **current** figure is stamped *since <date>*, not presented as a value;
 *  3. their recent rows are listed underneath with the day each one started and who set it, so
 *     *"when did this change?"* is answerable here rather than only in the audit log;
 *  4. the dialog's date field is required, labelled **Starts on**, and its button reads *Set
 *     from 1 November 2026*.
 *
 * The full trail — who, what, from what, when — is `audit_logs`, written by `setSalary()` in
 * the same transaction. Nothing on this page writes a second one.
 *
 * ## Nothing here ranks anybody
 *
 * Fifteen salaries on one screen is a list, in alphabetical order, with no total, no average,
 * no highest-paid and no comparison of any kind (Part H §1, and Part B §3 rule 12).
 */

defineOptions({
    layout: (props: SharedProps) => {
        const surface = props.auth.user?.surface;

        if (surface === 'accountant') {
            return AccountantLayout;
        }

        return surface === 'employee' ? EmployeeLayout : AdminLayout;
    },
});

defineProps<{
    /** The day the "current" figures are read as at, stated rather than implied. */
    asAt: string;
    currency: string;
    employees: SalaryEmployee[];
}>();
</script>

<template>
    <Head title="Salary settings" />

    <PageShell
        title="Salary settings"
        description="What each person is paid, and from when. A change starts on a date — months that have already been drafted keep the figures they were drafted with."
        :breadcrumb="[{ label: 'Payroll' }, { label: 'Salary settings' }]"
    >
        <div class="flex min-w-0 flex-col gap-4">
            <p class="text-sm text-muted-foreground">
                Current figures are as at {{ formatEffectiveDate(asAt) }}. Every change is recorded in the audit log.
            </p>

            <EmptyState
                v-if="employees.length === 0"
                :icon="Users"
                title="No active employees"
                description="Salary settings list everybody who currently works here. Nobody is active right now."
            />

            <ul v-else class="flex min-w-0 flex-col gap-4">
                <li v-for="employee in employees" :key="employee.id" class="min-w-0">
                    <Card class="min-w-0 gap-4 p-4 md:p-6">
                        <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 flex-col gap-1">
                                <h2 class="truncate text-base font-semibold tracking-tight">{{ employee.name }}</h2>
                                <p v-if="employee.role" class="truncate text-xs text-muted-foreground">
                                    {{ employee.role }}
                                </p>
                            </div>
                            <SalaryEditDialog :employee="employee" :currency="currency" />
                        </div>

                        <!--
                            The current figure, stamped with the day it started. A number with
                            no date on this screen would be a number somebody could read as
                            "the salary", which is the misunderstanding the whole design is
                            built against.
                        -->
                        <dl v-if="employee.current" class="flex min-w-0 flex-wrap gap-x-8 gap-y-3">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <dt class="text-xs text-muted-foreground">Base salary</dt>
                                <dd class="text-lg font-semibold tabular-nums">
                                    {{ formatMoney(employee.current.base_salary, currency) }}
                                </dd>
                            </div>
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <dt class="text-xs text-muted-foreground">In force since</dt>
                                <dd class="text-sm font-medium">
                                    {{ formatEffectiveDate(employee.current.effective_from) }}
                                </dd>
                            </div>
                        </dl>

                        <p v-else class="text-sm text-muted-foreground">
                            No salary set yet. Until there is one, {{ employee.name }} is left out of every payroll
                            draft.
                        </p>

                        <!--
                            "When did this change?" answered here rather than only in the audit
                            log. A real table with row headers, so the columns stay connected
                            for a screen reader, and figures right-aligned in `tabular-nums` so
                            two salaries line up on their decimal points.
                        -->
                        <section
                            v-if="employee.history.length > 0"
                            class="flex min-w-0 flex-col gap-2 border-t border-border pt-4"
                        >
                            <h3 class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                <History class="size-3.5" aria-hidden="true" />
                                Salary history
                            </h3>

                            <div class="min-w-0 overflow-x-auto">
                                <table class="w-full min-w-0 border-collapse text-sm">
                                    <caption class="sr-only">
                                        Every salary set for {{ employee.name }}, newest first
                                    </caption>
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th scope="col" class="py-2 pr-3 text-left font-medium text-muted-foreground">
                                                Starts on
                                            </th>
                                            <th scope="col" class="px-3 py-2 text-right font-medium text-muted-foreground">
                                                Base
                                            </th>
                                            <th scope="col" class="py-2 pl-3 text-left font-medium text-muted-foreground">
                                                Set by
                                            </th>
                                            <th scope="col" class="w-10 py-2 pl-3">
                                                <span class="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in employee.history" :key="row.id" class="border-b border-border/60">
                                            <th scope="row" class="py-2 pr-3 text-left font-normal whitespace-nowrap">
                                                {{ formatEffectiveDate(row.effective_from) }}
                                            </th>
                                            <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">
                                                {{ formatMoney(row.base_salary, currency) }}
                                            </td>
                                            <td class="py-2 pl-3 text-muted-foreground">{{ row.set_by ?? 'Unknown' }}</td>
                                            <td class="py-1 pl-3 text-right">
                                                <SalaryDeleteButton :row="row" :employee-name="employee.name" :currency="currency" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <p v-if="employee.history_total > employee.history.length" class="text-xs text-muted-foreground">
                                Showing the most recent {{ employee.history.length }} of
                                {{ employee.history_total }} changes. The rest are in the audit log.
                            </p>
                        </section>
                    </Card>
                </li>
            </ul>
        </div>
    </PageShell>
</template>
