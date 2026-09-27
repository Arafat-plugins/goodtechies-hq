<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, ReceiptText } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { formatMoney } from '@/Components/Finance/finance';
import type { PayslipRow } from '@/Components/Payroll/payslip';
import { payslipRoutes } from '@/Components/Payroll/payslip';
import PageShell from '@/Components/PageShell.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Card } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * **My Payslip** — this person's own months, newest first (Part C §1, Part D §14, Phase 9).
 *
 * ## One page for every surface, the Accountant included
 *
 * The layout is picked from `auth.user.surface`, exactly as `Pages/Shared/Messages.vue`,
 * `Leave.vue`, `Attendance.vue` and `Profile.vue` do. Part C §1 gives *View own payslip* a ✅ in
 * **every** column of the matrix and says underneath that the Accountant shell therefore
 * carries My Payslip — so an Accountant reaches `/payslip` in the **Accountant** shell, at the
 * same URL an Admin uses, and this file imports nothing from `Pages/Admin/`.
 *
 * Nothing on this page is anybody else's. `PayrollService::itemsFor()` is the `WHERE` clause;
 * there is no filter here and nothing for a screen to drop.
 *
 * ## Released months and provisional ones are both shown, and told apart in words
 *
 * A month reads as a **payslip** only once it is `paid` (see `PayslipController`'s note for the
 * argument: `approved` items are still editable, a lock can be reversed, and `paid` is the only
 * terminal status). Everything before that is listed under its own heading and marked
 * **Provisional** — because hiding it would be worse, not safer. The draft is this person's own
 * base and allowance, auto-created on the 1st from their own salary; a My Payslip screen that
 * is empty for three weeks of every month teaches people it is broken, and an employee who sees
 * a draft figure and believes it is final is exactly the complaint this labelling prevents.
 *
 * The distinction is carried by a **heading and a sentence**, not by a tint: DESIGN.md §5.6,
 * and this is the one page in the application where being wrong about it costs somebody money.
 *
 * ## An empty state that is not an error
 *
 * A new joiner has no payslips and that is normal. They get a plain `EmptyState` that says the
 * first one arrives after their first payroll run — not a warning, not a retry.
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
    subject: { id: number | null; name: string };
    currency: string;
    items: PayslipRow[];
}>();

/**
 * The two groups. `release.released` is the SERVER's answer — no status is compared here, so
 * there is no second copy of the rule to drift (decision 2-37).
 */
const released = computed(() => props.items.filter((item) => item.release.released));
const provisional = computed(() => props.items.filter((item) => !item.release.released));
</script>

<template>
    <Head title="My payslips" />

    <PageShell
        title="My payslips"
        description="Your own pay, month by month. Nobody else's figures are on this screen."
    >
        <div class="flex min-w-0 flex-col gap-8">
            <EmptyState
                v-if="items.length === 0"
                :icon="ReceiptText"
                title="No payslips yet"
                description="Your first payslip appears here after the first payroll run that includes you. Nothing is missing."
            />

            <template v-else>
                <!--
                    Released first: the months somebody actually came here to look up. A
                    heading rather than a badge column, so the grouping survives a screen
                    reader, greyscale and a printer.
                -->
                <section v-if="released.length > 0" aria-labelledby="payslips-released" class="flex min-w-0 flex-col gap-3">
                    <div class="flex min-w-0 flex-col gap-1">
                        <h2 id="payslips-released" class="text-base font-semibold tracking-tight">Payslips</h2>
                        <p class="text-sm text-muted-foreground">Paid months. These figures are final.</p>
                    </div>

                    <ul class="flex min-w-0 flex-col gap-3">
                        <li v-for="item in released" :key="item.id" class="min-w-0">
                            <Link
                                :href="payslipRoutes(item.id).show"
                                class="block min-w-0 rounded-xl outline-none focus-visible:ring-3 focus-visible:ring-ring"
                            >
                                <Card class="min-w-0 flex-row items-center gap-4 p-4 transition-colors hover:bg-accent/40">
                                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                                        <p class="truncate text-sm font-medium">{{ item.period?.label ?? 'Unknown month' }}</p>
                                        <StatusBadge
                                            v-if="item.period?.state"
                                            :status="item.period.state"
                                            :label="item.period.status_label ?? undefined"
                                            size="sm"
                                            class="self-start"
                                        />
                                    </div>
                                    <p class="shrink-0 text-right text-base font-semibold tabular-nums whitespace-nowrap">
                                        {{ formatMoney(item.net_salary, currency) }}
                                        <span class="sr-only">net pay</span>
                                    </p>
                                    <ChevronRight class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                </Card>
                            </Link>
                        </li>
                    </ul>
                </section>

                <!--
                    Everything before Paid. Same rows, own heading, and a sentence under it
                    that says what the figures are — legible rather than hidden, and labelled
                    rather than left to look like a payslip.
                -->
                <section v-if="provisional.length > 0" aria-labelledby="payslips-provisional" class="flex min-w-0 flex-col gap-3">
                    <div class="flex min-w-0 flex-col gap-1">
                        <h2 id="payslips-provisional" class="text-base font-semibold tracking-tight">
                            Not paid yet — provisional
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            These months are still being worked on. The figures can change before payday, so they are
                            not payslips yet.
                        </p>
                    </div>

                    <ul class="flex min-w-0 flex-col gap-3">
                        <li v-for="item in provisional" :key="item.id" class="min-w-0">
                            <Link
                                :href="payslipRoutes(item.id).show"
                                class="block min-w-0 rounded-xl outline-none focus-visible:ring-3 focus-visible:ring-ring"
                            >
                                <Card class="min-w-0 flex-row items-center gap-4 border-dashed p-4 transition-colors hover:bg-accent/40">
                                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                                        <p class="truncate text-sm font-medium">
                                            {{ item.period?.label ?? 'Unknown month' }}
                                            <span class="font-normal text-muted-foreground">· Provisional</span>
                                        </p>
                                        <StatusBadge
                                            v-if="item.period?.state"
                                            :status="item.period.state"
                                            :label="item.period.status_label ?? undefined"
                                            size="sm"
                                            class="self-start"
                                        />
                                    </div>
                                    <p class="shrink-0 text-right text-base font-semibold tabular-nums whitespace-nowrap">
                                        {{ formatMoney(item.net_salary, currency) }}
                                        <span class="sr-only">provisional net pay</span>
                                    </p>
                                    <ChevronRight class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                </Card>
                            </Link>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </PageShell>
</template>
