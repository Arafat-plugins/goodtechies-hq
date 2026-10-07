<script setup lang="ts">
import { computed } from 'vue';
import type { ActivityMinute, ActivitySession, ActivityState } from '@/Components/Activity/activity';
import { stateClass, stateLabel } from '@/Components/Activity/activity';
import { cn } from '@/lib/utils';

/**
 * Polish 031: the whole day on ONE line, like a calendar's day view — not a card per timer
 * entry. The track runs from the hour the first entry started to the hour the last one ended;
 * every tracked minute is a coloured cell at its own time (active, video, call, idle), each
 * entry's stretch carries a hairline frame with its task's name on hover, and the gaps between
 * entries are the empty track. Hour marks underneath, in am/pm.
 */
const props = defineProps<{
    sessions: ActivitySession[];
}>();

const STATES: ActivityState[] = ['active', 'media', 'call', 'idle'];
const MINUTE = 60_000;
const HOUR = 60 * MINUTE;

function clock(ms: number): string {
    return new Date(ms).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
}

function hourLabel(ms: number): string {
    return new Date(ms).toLocaleTimeString(undefined, { hour: 'numeric', hour12: true });
}

const span = computed(() => {
    const starts = props.sessions.map((session) => Date.parse(session.started_at)).filter((value) => !Number.isNaN(value));
    const ends = props.sessions.map((session) => (session.ended_at ? Date.parse(session.ended_at) : Date.now()));

    if (!starts.length) {
        return null;
    }

    const from = new Date(Math.min(...starts));
    from.setMinutes(0, 0, 0);

    const to = new Date(Math.max(...ends));
    if (to.getMinutes() !== 0 || to.getSeconds() !== 0) {
        to.setHours(to.getHours() + 1, 0, 0, 0);
    }

    return { from: from.getTime(), to: Math.max(to.getTime(), from.getTime() + HOUR) };
});

function percent(ms: number): number {
    if (!span.value) {
        return 0;
    }

    return ((ms - span.value.from) / (span.value.to - span.value.from)) * 100;
}

interface Block {
    id: number;
    left: number;
    width: number;
    title: string;
    cells: { key: number; left: number; width: number; state: ActivityState; label: string }[];
}

const blocks = computed<Block[]>(() => {
    if (!span.value) {
        return [];
    }

    const total = span.value.to - span.value.from;

    return props.sessions.map((session) => {
        const start = Date.parse(session.started_at);
        const end = session.ended_at ? Date.parse(session.ended_at) : Date.now();
        const name = [session.task?.name ?? 'No task', session.project?.name].filter(Boolean).join(' · ');

        return {
            id: session.id,
            left: percent(start),
            width: Math.max(((end - start) / total) * 100, 0.2),
            title: `${name} — ${clock(start)} to ${session.ended_at ? clock(end) : 'now'}`,
            cells: session.minutes.map((minute: ActivityMinute) => {
                const at = Date.parse(minute.minute);
                const parts = [clock(at), stateLabel(minute.state)];

                if (minute.host) {
                    parts.push(minute.host);
                }

                return {
                    key: at,
                    left: percent(at),
                    width: (MINUTE / total) * 100,
                    state: minute.state,
                    label: `${parts.join(' · ')} — ${session.task?.name ?? 'No task'}`,
                };
            }),
        };
    });
});

const hours = computed(() => {
    if (!span.value) {
        return [];
    }

    const marks: { at: number; left: number; label: string }[] = [];
    const count = (span.value.to - span.value.from) / HOUR;
    const step = count > 12 ? 2 : 1;

    for (let at = span.value.from; at <= span.value.to; at += HOUR * step) {
        marks.push({ at, left: percent(at), label: hourLabel(at) });
    }

    return marks;
});
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2">
        <!-- At phone width the line scrolls sideways inside this box, never the page. -->
        <div class="min-w-0 overflow-x-auto pb-1">
            <div class="relative min-w-2xl md:min-w-0">
                <!-- Hour grid lines behind the track. -->
                <div class="relative h-10 rounded-md border bg-muted">
                    <span
                        v-for="mark in hours"
                        :key="`grid-${mark.at}`"
                        class="absolute inset-y-0 w-px bg-border"
                        :style="{ left: `${mark.left}%` }"
                        aria-hidden="true"
                    />

                    <!-- One frame per timer entry, its minutes inside it. -->
                    <div
                        v-for="block in blocks"
                        :key="block.id"
                        class="absolute inset-y-1 rounded-sm ring-1 ring-border"
                        :style="{ left: `${block.left}%`, width: `${block.width}%` }"
                        :title="block.title"
                    />
                    <template v-for="block in blocks" :key="`cells-${block.id}`">
                        <span
                            v-for="cell in block.cells"
                            :key="cell.key"
                            role="img"
                            :aria-label="cell.label"
                            :title="cell.label"
                            :class="cn('absolute inset-y-1.5', stateClass(cell.state))"
                            :style="{ left: `${cell.left}%`, width: `calc(${cell.width}% + 0.5px)` }"
                        />
                    </template>
                </div>

                <!-- Hour labels. -->
                <div class="relative mt-1 h-4 text-xs text-muted-foreground tabular-nums" aria-hidden="true">
                    <span
                        v-for="(mark, index) in hours"
                        :key="`label-${mark.at}`"
                        :class="cn('absolute whitespace-nowrap', index === 0 ? '' : index === hours.length - 1 ? '-translate-x-full' : '-translate-x-1/2')"
                        :style="{ left: `${mark.left}%` }"
                    >
                        {{ mark.label }}
                    </span>
                </div>
            </div>
        </div>

        <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <li v-for="state in STATES" :key="state" class="flex items-center gap-1.5">
                <span :class="cn('size-2.5 rounded-sm', stateClass(state))" aria-hidden="true" />
                {{ stateLabel(state) }}
            </li>
            <li class="flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm ring-1 ring-border" aria-hidden="true" />
                One timer entry
            </li>
        </ul>
    </div>
</template>
