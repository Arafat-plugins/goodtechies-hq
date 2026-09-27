<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { PayrollPeriod } from '@/Components/Payroll/payroll';
import { formatMoney, payrollActions, payrollLines, payrollRoutes } from '@/Components/Payroll/payroll';

/**
 * **Every month of payroll, newest first** — where it got to, how many people are on it, what
 * it came to, and what this viewer may do to it next.
 *
 * A real table with a caption and `<th scope="row">` on the month, because that is what it is:
 * one row per month, four facts each. Five columns fit at 360 px only if two of them stop being
 * columns, so below `md` the same rows render as a stacked list of cards — the shape DESIGN.md
 * §3 gives `DataTable` at the same breakpoint, for the same reason.
 *
 * **The "next" column is `payrollActions()` and never a role test.** It lists the labels of the
 * moves the server says this person may make from this status, so the Accountant's list and the
 * Admin's differ on the same row without this component knowing why. A month with nothing for
 * this viewer says so rather than showing an empty cell.
 *
 * Nothing here adds anything up: `net_total` is `SUM(net_salary)` in PostgreSQL and
 * `items_count` is a `COUNT` beside it, both sent per row. `items_count` and `net_total` are
 * **optional keys** — absent means the caller did not ask, which is not the same as zero — so
 * both are rendered defensively rather than defaulted.
 */
defineProps<{
    periods: PayrollPeriod[];
    currency: string;
}>();

/** The verbs this viewer may reach from here, as one short phrase. */
function nextFor(period: PayrollPeriod): string {
    const labels = payrollActions(period).map((action) => action.label);

    return labels.length === 0 ? 'Nothing for you' : labels.join(' · ');
}
</script>

<template>
    <div class="min-w-0">
        <!-- ── Below md: one card per month ───────────────────────────────────────── -->
        <ul class="flex min-w-0 list-none flex-col gap-3 md:hidden">
            <li
                v-for="period in periods"
                :key="period.id"
                class="min-w-0 rounded-lg border border-border bg-card p-4 shadow-raised"
            >
                <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
                    <Link
                        :href="payrollRoutes.show(period.id)"
                        class="truncate text-sm font-medium text-card-foreground underline-offset-4 hover:underline"
                    >
                        {{ period.label }}
                    </Link>
                    <StatusBadge
                        v-if="period.state && period.status_label"
                        :status="period.state"
                        :label="period.status_label"
                        size="sm"
                    />
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">Lines</dt>
                        <dd class="tabular-nums">
                            {{ period.items_count === undefined ? '—' : payrollLines(period.items_count) }}
                        </dd>
                    </div>
                    <div class="text-right">
                        <dt class="text-xs text-muted-foreground">Net total</dt>
                        <dd class="tabular-nums">
                            {{ period.net_total === undefined ? '—' : formatMoney(period.net_total, currency) }}
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-xs text-muted-foreground">What you can do next</dt>
                        <dd>{{ nextFor(period) }}</dd>
                    </div>
                </dl>
            </li>
        </ul>

        <!-- ── md and up: the table ────────────────────────────────────────────────── -->
        <div class="hidden min-w-0 rounded-lg border border-border bg-card shadow-raised md:block">
            <table class="w-full text-sm">
                <caption class="px-4 pt-4 text-left text-xs text-muted-foreground">
                    Every month of payroll, newest first. The net total is the sum of that month’s lines,
                    worked out by the database.
                </caption>
                <thead>
                    <tr class="border-b border-border">
                        <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Month</th>
                        <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium text-muted-foreground">Lines</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium text-muted-foreground">
                            Net total
                        </th>
                        <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">
                            What you can do next
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="period in periods" :key="period.id" class="border-b border-border/60 last:border-0">
                        <th scope="row" class="px-4 py-3 text-left font-normal">
                            <Link
                                :href="payrollRoutes.show(period.id)"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ period.label }}
                            </Link>
                        </th>
                        <td class="px-4 py-3">
                            <StatusBadge
                                v-if="period.state && period.status_label"
                                :status="period.state"
                                :label="period.status_label"
                                size="sm"
                            />
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ period.items_count === undefined ? '—' : period.items_count }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ period.net_total === undefined ? '—' : formatMoney(period.net_total, currency) }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">{{ nextFor(period) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
