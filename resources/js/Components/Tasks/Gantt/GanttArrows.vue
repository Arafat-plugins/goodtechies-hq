<script setup lang="ts">
import { computed } from 'vue';
import type { GanttPayload, GanttPlacement } from '@/Components/Tasks/Gantt/gantt';
import { ganttEdgePath } from '@/Components/Tasks/Gantt/gantt';

/**
 * The dependency arrows, drawn over the bars.
 *
 * **Every arrow here has both ends on this timeline.** That is the server's doing, not this
 * component's: `BuildsGanttPayload` sends an edge only when both tasks are in the payload, so
 * an arrow can never point at a task this reader may not see. The two cases the payload
 * distinguishes are answered elsewhere — an edge whose other end is outside the WINDOW becomes
 * a count on the bar and a cell in the table, and an edge whose other end is outside the
 * reader's ACCESS produces nothing at all, here or anywhere.
 *
 * **It is `aria-hidden`, and that is not a shortcut.** A line between two rectangles is not
 * something a screen reader can usefully be given, and the same fact is already carried twice
 * in text: each bar's accessible name counts what it waits for and what waits on it, and the
 * table equivalent below the chart has a column for each direction. The picture is the
 * duplicate here, so the picture is the thing that is hidden.
 *
 * Colour comes from `currentColor` on a token class, so the arrows follow the theme and no hex
 * value appears anywhere (DESIGN.md §5.1).
 */

const props = defineProps<{
    payload: GanttPayload;
    placements: Map<number, GanttPlacement>;
    width: number;
    height: number;
    /** Unique per mount: two Gantts on one page must not share one `<marker>` id. */
    idPrefix: string;
}>();

const markerId = computed(() => `${props.idPrefix}-arrowhead`);

const paths = computed(() =>
    props.payload.edges
        .map((edge) => {
            const from = props.placements.get(edge.from);
            const to = props.placements.get(edge.to);

            return from === undefined || to === undefined
                ? null
                : { key: `${edge.from}-${edge.to}`, d: ganttEdgePath(from, to) };
        })
        .filter((path): path is { key: string; d: string } => path !== null),
);
</script>

<template>
    <svg
        v-if="paths.length > 0"
        class="pointer-events-none absolute top-0 left-0 text-muted-foreground"
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        aria-hidden="true"
        focusable="false"
    >
        <defs>
            <marker
                :id="markerId"
                markerWidth="7"
                markerHeight="6"
                refX="6"
                refY="3"
                orient="auto"
                markerUnits="userSpaceOnUse"
            >
                <path d="M 0 0 L 7 3 L 0 6 z" fill="currentColor" />
            </marker>
        </defs>

        <path
            v-for="path in paths"
            :key="path.key"
            :d="path.d"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linejoin="round"
            :marker-end="`url(#${markerId})`"
        />
    </svg>
</template>
