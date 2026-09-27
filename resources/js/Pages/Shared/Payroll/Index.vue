<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarClock, HandCoins, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import type { PayrollCurrentMonth, PayrollPeriod } from '@/Components/Payroll/payroll';
import { formatMoney, payrollRoutes } from '@/Components/Payroll/payroll';
import PayrollPeriodTable from '@/Components/Payroll/PayrollPeriodTable.vue';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
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

/** The id the Draft control carries, so focus can be put back on it after a navigation. */
const DRAFT_BUTTON_ID = 'payroll-create-draft';

const drafting = ref(false);

const mayDraft = computed(() => props.permissions.can_create && !props.current_month.has_period);

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

function createDraft(): void {
    if (drafting.value) {
        return;
    }

    drafting.value = true;

    router.post(
        payrollRoutes.store(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                drafting.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Payroll" />

    <PageShell
        title="Payroll"
        description="Every month of payroll and where it has got to. A month is drafted on the 1st from everybody's current salary, calculated against approved unpaid leave, then reviewed, approved, locked and paid."
        :breadcrumb="[{ label: 'Finance' }, { label: 'Payroll' }]"
    >
        <template #actions>
            <Button v-if="mayDraft" :id="DRAFT_BUTTON_ID" :disabled="drafting" @click="createDraft">
                <Plus aria-hidden="true" />
                {{ drafting ? 'Drafting…' : `Create the draft for ${current_month.label}` }}
            </Button>
        </template>

        <div class="flex min-w-0 flex-col gap-4">
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
                        :description="`A payroll period is created automatically on the 1st of each month, with one line per active employee at their current salary. Nothing is missing — ${current_month.label} simply has not been drafted yet.`"
                    >
                        <template #action>
                            <Button v-if="mayDraft" :disabled="drafting" @click="createDraft">
                                <Plus aria-hidden="true" />
                                {{ drafting ? 'Drafting…' : `Create the draft for ${current_month.label}` }}
                            </Button>
                        </template>
                    </EmptyState>
                </CardContent>
            </Card>

            <!--
                Said again, quietly, under a list that DOES have rows: the same question comes
                up on the 2nd of a month whose draft has not run, and the answer is the same.
            -->
            <Card v-if="periods.length > 0 && !current_month.has_period">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <CalendarClock class="size-4 text-muted-foreground" aria-hidden="true" />
                        {{ current_month.label }} has no payroll period yet
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex min-w-0 flex-col gap-3">
                    <p class="text-sm text-muted-foreground">
                        The draft is created on the 1st of the month from everybody's current salary. Until then
                        there is nothing to show for {{ current_month.label }}, which is expected rather than a
                        fault.
                    </p>
                    <div v-if="mayDraft">
                        <Button variant="outline" :disabled="drafting" @click="createDraft">
                            <Plus aria-hidden="true" />
                            {{ drafting ? 'Drafting…' : `Create it now` }}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </PageShell>
</template>
