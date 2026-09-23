<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Bell, CalendarDays, History, PartyPopper, Video } from '@lucide/vue';
import TimerHeroCard from '@/Components/Dashboard/TimerHeroCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageShell from '@/Components/PageShell.vue';
import StatCard from '@/Components/StatCard.vue';
import type { MyTaskBucket } from '@/Components/Tasks/MyTasks.vue';
import { bucketIcon, bucketSubline } from '@/Components/Tasks/MyTasks.vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import EmployeeLayout from '@/Layouts/EmployeeLayout.vue';
import type { TrackingMode } from '@/types';

defineOptions({ layout: EmployeeLayout });

/**
 * `taskStats` is the plan's five cards — My Tasks, Due Today, Overdue, In Progress,
 * Completed — each one a server-side COUNT over `Task::visibleTo()` narrowed to this person,
 * and each one carrying the link to the bucket it counted. Nothing on this page computes a
 * number, and there is no list here it could have been computed from.
 */
defineProps<{
    greetingName: string;
    today: string;
    trackingMode: TrackingMode;
    taskStats: MyTaskBucket[];
}>();

/**
 * The panels this page is still waiting on, each naming the phase that brings it.
 *
 * **A marker has to name a phase that has not happened.** Two of these said "Arrives in
 * Phase 2" while Phase 2 was shipping the very things they described, which is a placeholder
 * that has stopped being one. Notifications are live, so that panel now points at them
 * (below, outside this list). "Recent activity" is still unbuilt: the master prompt's Part I
 * table maps the employee "Recent activity" list to *Phase 2 / 7*, and Phase 2 closes without
 * it, so 7 is the number the plan itself leaves.
 */
const panels = [
    { title: 'My schedule', phase: 4, icon: CalendarDays, description: 'Your working hours and shifts will show here.' },
    { title: 'Upcoming meetings', phase: 7, icon: Video, description: 'Meetings you are invited to will show here.' },
    { title: 'Upcoming holidays', phase: 5, icon: PartyPopper, description: 'Company holidays will show here.' },
    { title: 'Recent activity', phase: 7, icon: History, description: 'Your latest task changes will show here.' },
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

        <!--
            Cards rather than the read-only row this used to be: every one of them is now a
            real number that leads to the tasks it counted, and a number you can act on is a
            card, not a term in a definition list. Zero is a real answer here too — the
            sub-line under a nought reads "Nothing is late", not an empty state.
        -->
        <section
            v-if="taskStats.length > 0"
            aria-label="My tasks"
            class="grid min-w-0 grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5"
        >
            <StatCard
                v-for="stat in taskStats"
                :key="stat.key"
                size="compact"
                :label="stat.label"
                :value="stat.count"
                :sub="bucketSubline(stat.key, stat.count)"
                :icon="bucketIcon(stat.key)"
                :href="stat.href"
            />
        </section>

        <section aria-label="Coming up" class="grid gap-4 md:grid-cols-2">
            <!--
                Notifications are built, so this panel is not a placeholder any more. It does
                not re-list the newest ten — the bell already does, from one polled endpoint,
                and a second reader of it here would be the duplicate DESIGN.md §5.8 forbids.
                It names where they are and opens the Center.
            -->
            <Card class="min-w-0 gap-4 p-6 shadow-xs">
                <h2 class="text-sm font-medium">Notifications</h2>
                <EmptyState
                    :icon="Bell"
                    title="In the bell, and in the Center"
                    description="Assignments, review verdicts and comments land on the bell in the top bar, with an unread count. The Center keeps all of them."
                >
                    <template #action>
                        <Button as-child size="sm" variant="outline">
                            <Link href="/notifications">Open the Notification Center</Link>
                        </Button>
                    </template>
                </EmptyState>
            </Card>

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
