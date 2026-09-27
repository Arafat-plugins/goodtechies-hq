<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { GanttPayload } from '@/Components/Tasks/Gantt/gantt';
import { ganttFormatDate, ganttProjectLabel } from '@/Components/Tasks/Gantt/gantt';
import type { TaskSurface } from '@/Components/Tasks/taskDetail';
import { taskRoutes } from '@/Components/Tasks/taskDetail';

/**
 * The chart, as a table.
 *
 * A Gantt is the hardest thing in this application to make accessible, and the honest answer
 * is not to make a grid of absolutely-positioned rectangles navigable — it is to publish the
 * same data in a shape that already works everywhere. Every fact the chart draws is a column
 * here: which project, which task, what state it is in, when it starts, when it is due, which
 * of the three shapes it is, and how many things it waits for and blocks in each direction.
 *
 * It is a real `<table>` with a `<caption>` naming the window, one `<tbody>` per project, a
 * `<th scope="colgroup">` for the project heading and a `<th scope="row">` for each task, so a
 * screen reader announces *"Buffalo Modular — SEO, Optimize Home Model pages, due 28
 * September"* without anybody having to invent an ARIA grid.
 *
 * It is behind a toggle rather than always rendered, because two copies of the same rows in
 * the accessibility tree is its own kind of unusable — the chart's bars are the interactive
 * controls and stay reachable, and this is the reading of them.
 *
 * `off-screen` counts are the deliberate half-answer of the dependency rule: a task whose
 * prerequisite is outside the window is told so, and a task whose prerequisite is outside its
 * reader's access is told nothing, because there is nothing it may be told.
 */

defineProps<{
    payload: GanttPayload;
    surface: TaskSurface;
}>();

function dependencyCell(visible: number, offscreen: number): string {
    if (visible === 0 && offscreen === 0) {
        return '—';
    }

    const parts: string[] = [];

    if (visible > 0) {
        parts.push(`${visible} here`);
    }

    if (offscreen > 0) {
        parts.push(`${offscreen} outside this window`);
    }

    return parts.join(', ');
}
</script>

<template>
    <!--
        Its own scroller, and deliberately NOT a `DataTable` card: decision 8-30 records that
        those are `overflow-hidden` with no horizontal scroller, so a wide table is clipped
        rather than scrollable and the first thing to go is the last column.
    -->
    <div class="overflow-x-auto rounded-xl border bg-card shadow-raised">
        <table class="w-full min-w-3xl caption-bottom text-sm">
            <caption class="px-4 py-3 text-left text-xs text-muted-foreground">
                Every task on the timeline between {{ ganttFormatDate(payload.window.from) }} and
                {{ ganttFormatDate(payload.window.to) }}, grouped by project. The same rows the chart draws.
            </caption>

            <thead>
                <tr class="border-b text-left text-xs text-muted-foreground">
                    <th scope="col" class="px-4 py-2 font-medium">Task</th>
                    <th scope="col" class="px-4 py-2 font-medium">Status</th>
                    <th scope="col" class="px-4 py-2 font-medium">Starts</th>
                    <th scope="col" class="px-4 py-2 font-medium">Due</th>
                    <th scope="col" class="px-4 py-2 font-medium">Shape</th>
                    <th scope="col" class="px-4 py-2 font-medium">Waits for</th>
                    <th scope="col" class="px-4 py-2 font-medium">Blocks</th>
                </tr>
            </thead>

            <tbody v-for="row in payload.rows" :key="row.project_id" class="border-b last:border-b-0">
                <tr class="bg-muted/50">
                    <th scope="colgroup" colspan="7" class="px-4 py-2 text-left text-xs font-semibold">
                        {{ ganttProjectLabel(row, surface) }}
                    </th>
                </tr>
                <tr v-for="task in row.tasks" :key="task.id" class="border-t">
                    <th scope="row" class="px-4 py-2 text-left font-normal">
                        <Link :href="taskRoutes(surface, task.id).show" class="underline-offset-4 hover:underline">
                            {{ task.title }}
                        </Link>
                    </th>
                    <td class="px-4 py-2">
                        <StatusBadge v-if="task.status_tone" :status="task.status_tone" :label="task.status_label ?? undefined" size="sm" />
                        <span v-else class="text-muted-foreground">—</span>
                    </td>
                    <td class="px-4 py-2 tabular-nums">
                        {{ task.gantt.start_date ? ganttFormatDate(task.gantt.start_date) : 'No start date' }}
                    </td>
                    <td class="px-4 py-2 tabular-nums">
                        {{ task.gantt.due_date ? ganttFormatDate(task.gantt.due_date) : 'No due date' }}
                    </td>
                    <td class="px-4 py-2 text-muted-foreground">
                        {{
                            task.gantt.shape === 'milestone'
                                ? 'Milestone'
                                : task.gantt.shape === 'open_ended'
                                  ? 'Open ended'
                                  : 'Bar'
                        }}
                    </td>
                    <td class="px-4 py-2 text-muted-foreground">
                        {{ dependencyCell(task.gantt.depends_on_visible, task.gantt.depends_on_offscreen) }}
                    </td>
                    <td class="px-4 py-2 text-muted-foreground">
                        {{ dependencyCell(task.gantt.blocks_visible, task.gantt.blocks_offscreen) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
