<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarOff, FileText, HandCoins, Plus, Receipt, Scale, TrendingUp, Wallet } from '@lucide/vue';
import AttentionList from '@/Components/Dashboard/AttentionList.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AccountantLayout from '@/Layouts/AccountantLayout.vue';

defineOptions({ layout: AccountantLayout });

defineProps<{
    greetingName: string;
    today: string;
}>();

/**
 * Money first: these four are what an accountant opens the app for, so they are the top
 * tier and the only cards at this weight. None has a ledger behind it yet, so each keeps
 * the "—" and its phase line; the sparkline slot stays unfilled until Phase 8 has a
 * series, rather than being seeded with a shape.
 */
const monthStats = [
    { label: 'Income', phase: 8, icon: TrendingUp },
    { label: 'Expenses', phase: 8, icon: Receipt },
    { label: 'Payroll', phase: 9, icon: HandCoins },
    { label: 'Operating result', phase: 8, icon: Scale },
];

const panels = [
    { title: 'My leave', phase: 5, icon: CalendarOff, description: 'Your leave balance and requests will show here.' },
    { title: 'My payslip', phase: 9, icon: FileText, description: 'Your latest payslip will show here.' },
];
</script>

<template>
    <Head title="Dashboard" />

    <PageShell title="Dashboard" :greeting="{ name: greetingName, today }">
        <!--
            Disabled, with no route behind it: the expense form itself arrives in Phase 8. The
            phase line is printed, not left in `title` — a disabled button is out of the tab order
            and a tooltip needs a hover, so keyboard and touch users saw a dead grey button and no
            reason for it. Same shape as the employee hero's `Clock in` (TimerHeroCard).
        -->
        <template #actions>
            <div class="flex shrink-0 flex-col gap-1 sm:items-end">
                <Button disabled title="Arrives in Phase 8">
                    <Plus aria-hidden="true" />
                    Record expense
                </Button>
                <p class="text-xs text-muted-foreground">Arrives in Phase 8</p>
            </div>
        </template>

        <section aria-labelledby="this-month" class="flex flex-col gap-4">
            <h2 id="this-month" class="text-base font-semibold tracking-tight">This month</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    v-for="stat in monthStats"
                    :key="stat.label"
                    :label="stat.label"
                    :phase="stat.phase"
                    :icon="stat.icon"
                />
            </div>
        </section>

        <AttentionList
            title="Outstanding"
            :empty-icon="Wallet"
            empty-title="Nothing outstanding"
            empty-description="Unpaid invoices, unapproved expenses and payroll runs arrive in Phases 8–9"
        />

        <section aria-label="Me" class="grid gap-4 md:grid-cols-2">
            <Card v-for="panel in panels" :key="panel.title" class="min-w-0 gap-4 p-6 shadow-xs">
                <h2 class="text-sm font-medium">{{ panel.title }}</h2>
                <EmptyState
                    :icon="panel.icon"
                    :title="`Arrives in Phase ${panel.phase}`"
                    :description="panel.description"
                />
            </Card>
        </section>
    </PageShell>
</template>
