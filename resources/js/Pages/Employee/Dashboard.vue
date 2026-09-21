<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Bell,
    CalendarClock,
    CalendarDays,
    CircleAlert,
    CircleCheck,
    History,
    ListTodo,
    LoaderCircle,
    PartyPopper,
    Video,
} from '@lucide/vue';
import TimerHeroCard from '@/Components/Dashboard/TimerHeroCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import { Card } from '@/Components/ui/card';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { TrackingMode } from '@/types';

defineOptions({ layout: EmployeeLayout });

defineProps<{
    greetingName: string;
    today: string;
    trackingMode: TrackingMode;
}>();

/**
 * Demoted from five hero cards to one compact row inside a single card: they are counts
 * you read, not things you do, and the hero above is what the page is for. One shared
 * phase line under the row replaces five identical ones.
 */
const taskStats = [
    { label: 'Assigned', icon: ListTodo },
    { label: 'Due today', icon: CalendarClock },
    { label: 'Overdue', icon: CircleAlert },
    { label: 'In progress', icon: LoaderCircle },
    { label: 'Completed', icon: CircleCheck },
];

const panels = [
    { title: 'My schedule', phase: 4, icon: CalendarDays, description: 'Your working hours and shifts will show here.' },
    { title: 'Upcoming meetings', phase: 7, icon: Video, description: 'Meetings you are invited to will show here.' },
    { title: 'Upcoming holidays', phase: 5, icon: PartyPopper, description: 'Company holidays will show here.' },
    { title: 'Notifications', phase: 2, icon: Bell, description: 'Updates on your tasks will show here.' },
    { title: 'Recent activity', phase: 2, icon: History, description: 'Your latest task changes will show here.' },
];
</script>

<template>
    <Head title="Dashboard" />

    <!--
        No #actions slot: the hero card carries this page's only primary action, so the
        header stays the greeting and nothing else.
    -->
    <PageShell title="Dashboard" :greeting="{ name: greetingName, today }">
        <TimerHeroCard :mode="trackingMode" />

        <Card class="gap-4 p-4 shadow-xs">
            <h2 class="text-sm font-medium">My tasks</h2>
            <dl class="grid min-w-0 grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                <div v-for="stat in taskStats" :key="stat.label" class="flex min-w-0 flex-col gap-1">
                    <dt class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                        <component :is="stat.icon" class="size-3.5 shrink-0" aria-hidden="true" />
                        <span class="truncate">{{ stat.label }}</span>
                    </dt>
                    <dd class="text-xl font-semibold tabular-nums text-muted-foreground">
                        <span aria-hidden="true">—</span>
                        <span class="sr-only">Not available yet</span>
                    </dd>
                </div>
            </dl>
            <p class="text-xs text-muted-foreground">Arrives in Phase 2</p>
        </Card>

        <section aria-label="Coming up" class="grid gap-4 md:grid-cols-2">
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
