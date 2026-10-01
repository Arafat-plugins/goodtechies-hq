<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Timer } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { personTone } from '@/Components/Messages/people';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDuration } from '@/Components/Timer/timer';
import type { WorkingNowRow } from '@/Components/Timer/taskTimer';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Card } from '@/Components/ui/card';
import { useMinuteTicker } from '@/lib/minuteTicker';
import { cn } from '@/lib/utils';

/**
 * "Working now" — every open task timer: who, on which task, for how long. Flow F3.
 *
 * The server sends the rows to a watcher (`TimeEntryPolicy::watchLive`) and leaves the prop out
 * for anybody else, so a page mounts this only when it has the prop. Rows arrive ordered by name;
 * nothing here re-sorts them by time, and there is no percentage, no target and no judgement of
 * anybody's pace (Part H) — just the three facts.
 *
 * **The clock is local.** `elapsed_seconds` is as of the moment the server answered; a running
 * row adds the time since, on the shared minute ticker (`lib/minuteTicker.ts`). A tick re-renders
 * this card's labels and never the page around it. The page re-reads the prop itself when a
 * `task.changed` kind `timer` frame arrives (flow F1), so start, pause and stop show without a
 * reload.
 *
 * `limit` is the dashboard's compact card: the first few rows, then "+N more" to the Time page.
 */

const props = withDefaults(
    defineProps<{
        rows: WorkingNowRow[];
        /** Show at most this many rows, then "+N more" linking to `moreHref`. */
        limit?: number | null;
        moreHref?: string | null;
        /** `h2` id, so the page's section can be labelled by it. */
        headingId?: string;
    }>(),
    { limit: null, moreHref: null, headingId: 'working-now' },
);

const now = useMinuteTicker();
const receivedAt = ref(Date.now());

watch(
    () => props.rows,
    () => {
        receivedAt.value = Date.now();
    },
);

const shown = computed(() => (props.limit === null ? props.rows : props.rows.slice(0, props.limit)));
const hidden = computed(() => props.rows.length - shown.value.length);

const drawn = computed(() =>
    shown.value.map((row) => {
        const seconds = row.paused
            ? row.elapsed_seconds
            : row.elapsed_seconds + Math.max(0, Math.floor((now.value - receivedAt.value) / 1000));

        return { ...row, duration: formatDuration(seconds) };
    }),
);
</script>

<template>
    <Card class="min-w-0 gap-4 p-6" data-working-now>
        <div class="flex min-w-0 items-center justify-between gap-4">
            <h2 :id="headingId" class="text-sm font-medium">Working now</h2>
            <span v-if="rows.length" class="shrink-0 text-xs text-muted-foreground tabular-nums">
                {{ rows.length }} {{ rows.length === 1 ? 'person' : 'people' }}
            </span>
        </div>

        <EmptyState v-if="!rows.length" :icon="Timer" title="Nobody is timing a task right now." />

        <ul v-else class="flex min-w-0 flex-col divide-y">
            <li
                v-for="row in drawn"
                :key="row.id"
                data-working-now-row
                class="flex min-w-0 items-center gap-3 py-3 first:pt-0 last:pb-0"
            >
                <Avatar class="size-8 shrink-0">
                    <AvatarFallback :class="cn('text-xs', personTone(row.employee.id).avatar)" aria-hidden="true">
                        {{ row.employee.initials }}
                    </AvatarFallback>
                </Avatar>

                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                        <span class="truncate text-sm font-medium">{{ row.employee.name }}</span>
                        <StatusBadge v-if="row.paused" status="waiting" label="Paused" size="sm" />
                    </div>
                    <p class="flex min-w-0 flex-wrap items-baseline gap-x-2 text-sm">
                        <Link :href="row.task.href" class="min-w-0 truncate underline-offset-4 hover:underline">
                            {{ row.task.title }}
                        </Link>
                        <span v-if="row.project" class="min-w-0 truncate text-xs text-muted-foreground">
                            {{ row.project.name }}
                        </span>
                    </p>
                </div>

                <span
                    class="inline-flex shrink-0 items-center gap-2 text-sm tabular-nums"
                    :aria-label="row.paused ? `Paused at ${row.duration}` : `Timing for ${row.duration}`"
                >
                    <!-- Brief 024: the live dot breathes on a running row; a paused row has none. -->
                    <span
                        v-if="!row.paused"
                        data-live-breathe
                        class="size-2 shrink-0 rounded-full bg-status-done animate-live-breathe"
                        aria-hidden="true"
                    />
                    {{ row.duration }}
                </span>
            </li>
        </ul>

        <Link
            v-if="moreHref && hidden > 0"
            :href="moreHref"
            class="self-start text-xs text-muted-foreground underline-offset-4 hover:underline"
        >
            +{{ hidden }} more
        </Link>
        <Link
            v-else-if="moreHref && rows.length"
            :href="moreHref"
            class="self-start text-xs text-muted-foreground underline-offset-4 hover:underline"
        >
            Open Time
        </Link>
    </Card>
</template>
