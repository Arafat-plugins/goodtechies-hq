<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { HandCoins } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import type { PayrollCurrentMonth, PayrollPeriod } from '@/Components/Payroll/payroll';
import { formatMoney } from '@/Components/Payroll/payroll';
import PayrollDraftControl from '@/Components/Payroll/PayrollDraftControl.vue';
import PayrollPeriodTable from '@/Components/Payroll/PayrollPeriodTable.vue';
import { Card, CardContent } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { SharedProps } from '@/types';

/**
 * **The payroll period list** — every month of payroll, newest first (Part D §14, Phase 9).
 *
 * **One shared page, not one per shell.** Whose pay this is belongs to the agency and not to
 * the shell somebody is looking at, so `routes/shared.php` carries the endpoints behind
 * `can:payroll.draft` and this picks its layout from the viewer's own surface, exactly as
 * `Pages/Shared/Messages.vue` does. An Admin gets `AdminLayout`, the Accountant gets
 * `AccountantLayout`, and an Employee, a Remote employee or a Manager is refused 403 by the
 * route gate before this component exists.
 *
 * ## The empty state is honest about the schedule
 *
 * `hq:create-payroll-draft` makes the month's period **on the 1st**, from everybody's current
 * salary. So an empty list on the 12th of a fresh install, or before the first of the month,
 * is not a broken screen — it is a screen with nothing in it yet, and it says which is which.
 * A list that just showed *"Nothing here"* would have somebody filing a bug against a cron job
 * that has not been due yet.
 *
 * The Create-draft control is for the other case: a month that genuinely missed its 1st. It
 * appears only when the server said `permissions.can_create` **and** this month has no period,
 * because `PayrollService::createDraft()` refuses a second period for a month with a sentence
 * (decision 9-6) and a control whose only outcome is that sentence is a control that should not
 * be drawn.
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
    periods: PayrollPeriod[];
    current_month: PayrollCurrentMonth;
    permissions: { can_create: boolean };
    currency: string;
}>();

/** Polish 005: anybody who may draft can draft any month that has no payroll yet. */
const mayDraft = computed(() => props.permissions.can_create);

/**
 * What the list adds up to, said once at the top.
 *
 * `net_total` is an optional key — absent means a caller did not sum, which is not zero — so
 * the sentence is built only from the rows that actually carry one, and the figures themselves
 * are never added together here. The only arithmetic is counting rows.
 */
const summary = computed(() => {
    const count = props.periods.length;

    if (count === 0) {
        return 'No payroll month has been drafted yet.';
    }

    const newest = props.periods[0];
    const total = newest.net_total === undefined ? null : formatMoney(newest.net_total, props.currency);

    return `${count} payroll ${count === 1 ? 'month' : 'months'}. The newest is ${newest.label}${
        newest.status_label ? ` (${newest.status_label.toLowerCase()})` : ''
    }${total ? `, ${total} in all` : ''}.`;
});

</script>

<template>
    <Head title="Payroll" />

    <PageShell
        title="Payroll"
        description="Every month of payroll and where it has got to. A month is drafted on the 1st from everybody's current salary, calculated against approved unpaid leave, then reviewed, approved, locked and paid."
        :breadcrumb="[{ label: 'Finance' }, { label: 'Payroll' }]"
    >

        <div class="flex min-w-0 flex-col gap-4">
            <PayrollDraftControl v-if="mayDraft && periods.length > 0" :month="current_month" />

            <p class="text-sm text-muted-foreground">{{ summary }}</p>

            <PayrollPeriodTable v-if="periods.length > 0" :periods="periods" :currency="currency" />

            <!--
                The honest empty state. It names the schedule, because an empty payroll list
                before the 1st is the normal state of a new installation and looks identical to
                a broken one.
            -->
            <Card v-else>
                <CardContent>
                    <EmptyState
                        :icon="HandCoins"
                        title="No payroll month yet"
                        :description="`On the 1st of each month the payroll for the month just finished is drafted automatically, with one line per active employee at their salary. Choose a month to draft one now.`"
                    >
                        <template #action>
                            <PayrollDraftControl v-if="mayDraft" :month="current_month" />
                        </template>
                    </EmptyState>
                </CardContent>
            </Card>

        </div>
    </PageShell>
</template>
