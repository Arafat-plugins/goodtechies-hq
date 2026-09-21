<script setup lang="ts">
import { CalendarCheck, LogIn, Play, Timer } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import type { TrackingMode } from '@/types';

/**
 * Decision 0.5-5: the employee's timer is the dashboard hero, not a header widget — it is
 * the one thing an employee comes here to do, so it gets the top of the page and the page's
 * only primary button. A person who tracks neither way (`none`) has no hero at all; the
 * caller renders nothing rather than an empty card.
 */
const props = defineProps<{
    mode: TrackingMode;
    /** Daily target for the timer, as it should read. */
    target?: string;
}>();

const hero = computed(() => {
    if (props.mode === 'remote_timer') {
        return {
            label: 'Time today',
            icon: Timer,
            /** No timer exists yet, so the elapsed half of this is the placeholder dash. */
            target: props.target ?? '5h',
            action: 'Start timer',
            actionIcon: Play,
        };
    }

    if (props.mode === 'office_attendance') {
        return {
            label: 'Attendance today',
            icon: CalendarCheck,
            target: null,
            action: 'Clock in',
            actionIcon: LogIn,
        };
    }

    return null;
});
</script>

<template>
    <Card v-if="hero" class="gap-4 p-6 shadow-xs sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 flex-col gap-2">
            <p class="flex items-center gap-2 text-sm text-muted-foreground">
                <component :is="hero.icon" class="size-4 shrink-0" aria-hidden="true" />
                {{ hero.label }}
            </p>
            <p class="text-3xl font-semibold tabular-nums text-muted-foreground">
                <span aria-hidden="true">—</span>
                <span class="sr-only">Not available yet</span>
                <span v-if="hero.target" class="text-xl font-normal"> / {{ hero.target }}</span>
            </p>
        </div>

        <div class="flex shrink-0 flex-col gap-1 sm:items-end">
            <Button disabled title="Arrives in Phase 4">
                <component :is="hero.actionIcon" aria-hidden="true" />
                {{ hero.action }}
            </Button>
            <p class="text-xs text-muted-foreground">Arrives in Phase 4</p>
        </div>
    </Card>
</template>
