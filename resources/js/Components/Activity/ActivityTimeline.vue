<script setup lang="ts">
import { computed } from 'vue';
import type { ActivityMinute, ActivitySession, ActivityState } from '@/Components/Activity/activity';
import { stateClass, stateLabel } from '@/Components/Activity/activity';
import { cn } from '@/lib/utils';

/**
 * One timer session as a bar of minutes, start to end (or to now while it is open). Each minute
 * is a cell coloured by its state; a minute with no sample — a pause — is a gap. Hovering a cell
 * names the minute, its state and the domain. The legend below means no state is colour alone.
 */
const props = defineProps<{
    session: ActivitySession;
}>();

const STATES: ActivityState[] = ['active', 'media', 'call', 'idle'];

const MINUTE_MS = 60_000;

function floorMinute(ms: number): number {
    return Math.floor(ms / MINUTE_MS) * MINUTE_MS;
}

function clock(ms: number): string {
    const date = new Date(ms);

    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

interface Cell {
    key: number;
    sample: ActivityMinute | null;
    label: string;
}

const cells = computed<Cell[]>(() => {
    const start = floorMinute(Date.parse(props.session.started_at));
    const end = floorMinute(props.session.ended_at ? Date.parse(props.session.ended_at) : Date.now());

    if (Number.isNaN(start) || Number.isNaN(end)) {
        return [];
    }

    const byMinute = new Map<number, ActivityMinute>();

    for (const minute of props.session.minutes) {
        byMinute.set(floorMinute(Date.parse(minute.minute)), minute);
    }

    const rows: Cell[] = [];

    for (let at = start; at <= end; at += MINUTE_MS) {
        const sample = byMinute.get(at) ?? null;
        const parts = [clock(at), sample ? stateLabel(sample.state) : 'No activity recorded'];

        if (sample?.host) {
            parts.push(sample.host);
        }

        rows.push({ key: at, sample, label: parts.join(' · ') });
    }

    return rows;
});
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2">
        <!-- At 375 px the bar scrolls sideways inside this box, never the page. -->
        <div class="min-w-0 overflow-x-auto">
            <div class="flex h-6 min-w-2xl overflow-hidden rounded-sm border md:min-w-0">
                <span
                    v-for="cell in cells"
                    :key="cell.key"
                    role="img"
                    :aria-label="cell.label"
                    :title="cell.label"
                    :class="cn('h-full min-w-0 flex-1', cell.sample ? stateClass(cell.sample.state) : 'bg-transparent')"
                />
            </div>
        </div>
        <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <li v-for="state in STATES" :key="state" class="flex items-center gap-1.5">
                <span :class="cn('size-2.5 rounded-sm', stateClass(state))" aria-hidden="true" />
                {{ stateLabel(state) }}
            </li>
        </ul>
    </div>
</template>
