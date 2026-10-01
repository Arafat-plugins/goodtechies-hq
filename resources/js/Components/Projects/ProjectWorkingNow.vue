<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { personTone } from '@/Components/Messages/people';
import { formatDuration } from '@/Components/Timer/timer';
import type { WorkingNowRow } from '@/Components/Timer/taskTimer';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { useMinuteTicker } from '@/lib/minuteTicker';
import { cn } from '@/lib/utils';

/**
 * The Projects list's "Working now" marker (flow F3): a green dot, the words, up to three
 * overlapping avatars (then +N) and the longest elapsed time among the project's open timers.
 *
 * The rows are `TaskTimerService::workingNowByProject()[project]`, sent to watchers only; the
 * page re-reads them on a `task.changed` kind `timer` frame. The clock is local — a running row
 * adds the time since the payload arrived, on the shared minute ticker — and paused rows hold.
 * Nothing is ranked: the longest time is shown because it says how long the project has been
 * in hand, not who worked hardest (Part H).
 */

const MAX_AVATARS = 3;

const props = defineProps<{
    rows: WorkingNowRow[];
}>();

const now = useMinuteTicker();
const receivedAt = ref(Date.now());

watch(
    () => props.rows,
    () => {
        receivedAt.value = Date.now();
    },
);

const shown = computed(() => props.rows.slice(0, MAX_AVATARS));
const hidden = computed(() => Math.max(0, props.rows.length - MAX_AVATARS));

const longest = computed(() => {
    const since = Math.max(0, Math.floor((now.value - receivedAt.value) / 1000));

    return formatDuration(
        props.rows.reduce(
            (max, row) => Math.max(max, row.paused ? row.elapsed_seconds : row.elapsed_seconds + since),
            0,
        ),
    );
});

// Brief 024: the dot breathes only while at least one timer is running; all paused holds still.
const anyRunning = computed(() => props.rows.some((row) => !row.paused));

const label = computed(() => {
    const names = props.rows.map((row) => (row.paused ? `${row.employee.name} (paused)` : row.employee.name));

    return `Working now: ${names.join(', ')} · ${longest.value}`;
});
</script>

<template>
    <span
        v-if="rows.length"
        role="status"
        :aria-label="label"
        :title="label"
        data-project-working-now
        class="inline-flex max-w-full min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs"
    >
        <span class="inline-flex shrink-0 items-center gap-1.5 font-medium" aria-hidden="true">
            <span
                data-live-breathe
                :class="cn('size-2 shrink-0 rounded-full bg-status-done', anyRunning && 'animate-live-breathe')"
            />
            Working now
        </span>
        <span class="inline-flex shrink-0 items-center -space-x-1.5" aria-hidden="true">
            <Avatar v-for="row in shown" :key="row.id" class="size-5 ring-2 ring-card">
                <AvatarFallback :class="cn('text-xs', personTone(row.employee.id).avatar)">
                    {{ row.employee.initials }}
                </AvatarFallback>
            </Avatar>
            <span
                v-if="hidden > 0"
                class="relative inline-flex size-5 items-center justify-center rounded-full bg-muted text-xs text-muted-foreground ring-2 ring-card"
            >
                +{{ hidden }}
            </span>
        </span>
        <span class="shrink-0 text-muted-foreground tabular-nums" aria-hidden="true">{{ longest }}</span>
    </span>
</template>
