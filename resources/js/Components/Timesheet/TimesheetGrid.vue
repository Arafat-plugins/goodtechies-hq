<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import type { TimesheetCell, TimesheetDay, TimesheetRow, TimesheetTotals } from '@/Components/Timesheet/timesheet';
import { againstTarget, cellSentence, daySentence, formatDuration } from '@/Components/Timesheet/timesheet';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * One week of somebody's tracked hours — *rows are tasks, columns are days* (Part D §7).
 *
 * ## What it becomes at 360 px, and the measurement behind that
 *
 * Below `md` it is a **list, one section per day**; at `md` and up it is the grid. Both are in
 * the DOM (`md:hidden` / `hidden md:block`), which is the switch `DataTable` and
 * `AttendanceMonth` already make at the same breakpoint, so the repo has one responsive
 * contract and not three.
 *
 * The reason is measured, and it is decision 4-13's measurement taken one step further. At
 * 360 px with the page's 16 px gutters the content box is 328 px. The attendance month grid
 * has seven columns and nothing else, which gave it ~48 px a cell — already too little for a
 * status word. **This grid has seven day columns plus a task column and a total column**, and
 * a task column narrower than about 120 px cannot name a task at all. That leaves roughly
 * 23 px per day cell, where the shortest real value — `1h 30m` — needs about 40 px at
 * `text-xs`. There is no legal type size that fixes it, and a table that scrolls sideways is a
 * table nobody reads.
 *
 * So the phone gets the same information rotated: the week as seven day sections, each naming
 * its date, whether it is a working day, what was tracked against the target, and the tasks it
 * was tracked on. Nothing is dropped and nothing truncates — which is exactly what decision
 * 4-13 asked of the attendance list.
 *
 * ## Every state is a word
 *
 * An off day, today, a running timer, hours waiting for approval and hours that were refused
 * all print their words. The tint on an off-day column is a second encoding, never the carrier
 * (DESIGN.md §5.6), and every cell carries the whole sentence as its `aria-label` and `title`
 * so a truncated label is never the only copy.
 *
 * ## Nothing here decides what anybody may do
 *
 * `canAddTime` is `TimeEntryPolicy::create` resolved on the server for this subject. The
 * component only draws or omits a control; the endpoint checks again either way.
 */

const props = defineProps<{
    days: TimesheetDay[];
    rows: TimesheetRow[];
    totals: TimesheetTotals;
    /** Whose week this is, for the sentences a screen reader hears. */
    subjectName: string;
    /** Server-resolved. Omitted entirely rather than drawn disabled (DESIGN.md §5.12). */
    canAddTime?: boolean;
}>();

const emit = defineEmits<{ 'add-time': [payload: { date: string; taskId: number | null }] }>();

/** The table's caption — the one place the whole grid is described in a sentence. */
const caption = computed(
    () =>
        `${props.subjectName}: tracked hours per task for each day of the week. `
        + `Row totals on the right, day totals along the bottom.`,
);

/** The tasks that have anything on a given day, for the phone list. */
function rowsOn(day: TimesheetDay): { row: TimesheetRow; cell: TimesheetCell }[] {
    return props.rows
        .map((row) => ({ row, cell: row.cells.find((candidate) => candidate.date === day.date) }))
        .filter(
            (pair): pair is { row: TimesheetRow; cell: TimesheetCell } =>
                pair.cell !== undefined
                && (pair.cell.entry_count > 0
                    || pair.cell.counted_seconds > 0
                    || pair.cell.pending_seconds > 0
                    || pair.cell.rejected_seconds > 0),
        );
}

/**
 * A day column's tint. An off day is quieter and a future day quieter still — both say so in
 * words as well, so neither is carried by the shade.
 *
 * Today is NOT marked here. The ring goes on the column HEADING alone (`headClass`), because
 * `--ring` is the brand colour and DESIGN.md §5.3 allows it once on a screen — a ring repeated
 * down every cell of a column is five oranges where one will do, and the heading already says
 * the word.
 */
function dayClass(day: TimesheetDay): string {
    return cn(!day.is_working_day && 'bg-muted', day.is_future && 'opacity-60');
}

/** The heading of a day column: the tint, plus the one ring that marks today. */
function headClass(day: TimesheetDay): string {
    return cn(dayClass(day), day.is_today && 'ring-2 ring-inset ring-ring');
}

function add(date: string, taskId: number | null): void {
    emit('add-time', { date, taskId });
}
</script>

<template>
    <div class="min-w-0">
        <!-- ------------------------------------------------- below md: the day list -->
        <ul class="flex flex-col gap-3 md:hidden" :aria-label="`${subjectName}: this week, day by day`">
            <li
                v-for="day in days"
                :key="day.date"
                :class="
                    cn(
                        'flex flex-col gap-2 rounded-md border bg-card p-3 shadow-flat',
                        day.is_future && 'opacity-60',
                        day.is_today && 'ring-2 ring-ring',
                    )
                "
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <h3 class="text-sm font-medium">{{ day.label }}</h3>
                        <!-- Words, not shades. -->
                        <span v-if="day.is_today" class="text-xs font-medium">Today</span>
                        <span v-if="!day.is_working_day" class="text-xs text-muted-foreground">Off day</span>
                    </div>
                    <p class="text-sm tabular-nums">
                        {{ againstTarget(day.counted_seconds, day.target_seconds) }}
                    </p>
                </div>

                <ul v-if="rowsOn(day).length" class="flex flex-col gap-2">
                    <li
                        v-for="pair in rowsOn(day)"
                        :key="pair.row.key"
                        class="flex min-w-0 items-start justify-between gap-3 border-t pt-2"
                    >
                        <div class="min-w-0">
                            <p class="text-sm">{{ pair.row.task?.title ?? 'Task removed' }}</p>
                            <p v-if="pair.row.project" class="text-xs text-muted-foreground">
                                {{ pair.row.project.name }}
                            </p>
                            <p v-if="pair.cell.is_running" class="text-xs text-muted-foreground">
                                A timer is running on this task
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm font-medium tabular-nums">
                                {{ formatDuration(pair.cell.counted_seconds) }}
                            </p>
                            <p v-if="pair.cell.pending_seconds > 0" class="text-xs tabular-nums text-muted-foreground">
                                {{ formatDuration(pair.cell.pending_seconds) }} waiting for approval
                            </p>
                            <p v-if="pair.cell.rejected_seconds > 0" class="text-xs tabular-nums text-muted-foreground">
                                {{ formatDuration(pair.cell.rejected_seconds) }} not approved
                            </p>
                        </div>
                    </li>
                </ul>

                <!-- Zero is an answer. A day with nothing on it says so rather than going blank. -->
                <p v-else class="border-t pt-2 text-xs text-muted-foreground">Nothing tracked on this day.</p>

                <Button
                    v-if="canAddTime && !day.is_future"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="self-start"
                    @click="add(day.date, null)"
                >
                    <Plus aria-hidden="true" />
                    Add time on {{ day.short_label }}
                </Button>
            </li>
        </ul>

        <!-- --------------------------------------------- md and up: the seven-day grid -->
        <div class="hidden rounded-md border bg-card shadow-raised md:block">
            <table class="w-full table-fixed border-collapse text-sm">
                <caption class="sr-only">{{ caption }}</caption>

                <colgroup>
                    <col style="width: 27%" />
                    <col v-for="day in days" :key="`col-${day.date}`" style="width: 9%" />
                    <col style="width: 10%" />
                </colgroup>

                <thead>
                    <tr class="border-b">
                        <th scope="col" class="p-2 text-left text-xs font-medium text-muted-foreground">Task</th>
                        <th
                            v-for="day in days"
                            :key="day.date"
                            scope="col"
                            :class="cn('p-2 text-left align-top', headClass(day))"
                        >
                            <span class="sr-only">{{ daySentence(day) }}</span>
                            <span aria-hidden="true" class="flex flex-col gap-1">
                                <span class="text-xs font-medium">{{ day.short_label }}</span>
                                <span class="text-xs tabular-nums text-muted-foreground">
                                    {{ day.label.split(' ').slice(1).join(' ') }}
                                </span>
                                <span v-if="day.is_today" class="text-xs font-medium">Today</span>
                                <span v-else-if="!day.is_working_day" class="text-xs text-muted-foreground">
                                    Off day
                                </span>
                            </span>
                        </th>
                        <th scope="col" class="p-2 text-right text-xs font-medium text-muted-foreground">Total</th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="row in rows" :key="row.key" class="border-b">
                        <th scope="row" class="p-2 text-left align-top font-normal">
                            <span class="block text-xs break-words lg:text-sm">{{ row.task?.title ?? 'Task removed' }}</span>
                            <span v-if="row.project" class="block text-xs break-words text-muted-foreground">
                                {{ row.project.name }}
                            </span>
                        </th>

                        <td
                            v-for="(cell, index) in row.cells"
                            :key="cell.date"
                            :class="cn('p-1 align-top', dayClass(days[index]))"
                        >
                            <!--
                                The cell is a button only where time may be added, and only on a
                                day that has happened: a manual entry is clamped to the present
                                by TimerService, so a control on Friday would be a control that
                                silently moved the hours.
                            -->
                            <component
                                :is="canAddTime && !days[index].is_future ? 'button' : 'div'"
                                :type="canAddTime && !days[index].is_future ? 'button' : undefined"
                                :class="
                                    cn(
                                        'flex min-h-14 w-full flex-col gap-1 rounded-md p-1 text-left',
                                        canAddTime
                                            && !days[index].is_future
                                            && 'outline-none hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring/50',
                                    )
                                "
                                :aria-label="
                                    canAddTime && !days[index].is_future
                                        ? `Add time — ${cellSentence(row, cell, days[index])}`
                                        : cellSentence(row, cell, days[index])
                                "
                                :title="cellSentence(row, cell, days[index])"
                                @click="canAddTime && !days[index].is_future ? add(cell.date, row.task?.id ?? null) : undefined"
                            >
                                <span class="text-xs tabular-nums lg:text-sm" :class="cell.counted_seconds > 0 && 'font-medium'">
                                    {{ formatDuration(cell.counted_seconds) }}
                                </span>
                                <span
                                    v-if="cell.pending_seconds > 0"
                                    aria-hidden="true"
                                    class="text-xs leading-tight tabular-nums text-muted-foreground"
                                >
                                    {{ formatDuration(cell.pending_seconds) }} pending
                                </span>
                                <span
                                    v-if="cell.rejected_seconds > 0"
                                    aria-hidden="true"
                                    class="text-xs leading-tight tabular-nums text-muted-foreground"
                                >
                                    {{ formatDuration(cell.rejected_seconds) }} not approved
                                </span>
                                <span
                                    v-if="cell.is_running"
                                    aria-hidden="true"
                                    class="text-xs leading-tight text-muted-foreground"
                                >
                                    Running
                                </span>
                            </component>
                        </td>

                        <td class="p-2 text-right align-top">
                            <span class="text-xs font-medium tabular-nums lg:text-sm">
                                {{ formatDuration(row.counted_seconds) }}
                            </span>
                            <span
                                v-if="row.pending_seconds > 0"
                                class="block text-xs leading-tight tabular-nums text-muted-foreground"
                            >
                                {{ formatDuration(row.pending_seconds) }} pending
                            </span>
                        </td>
                    </tr>
                </tbody>

                <tfoot>
                    <tr>
                        <th scope="row" class="p-2 text-left text-xs font-medium text-muted-foreground">
                            Tracked
                            <!-- The target line, said in words under the label it belongs to. -->
                            <span v-if="totals.target_seconds !== null" class="block font-normal">
                                Target from the work schedule
                            </span>
                        </th>
                        <td
                            v-for="day in days"
                            :key="`total-${day.date}`"
                            :class="cn('p-2 text-left align-top', dayClass(day))"
                        >
                            <span class="text-xs font-medium tabular-nums lg:text-sm">
                                {{ formatDuration(day.counted_seconds) }}
                            </span>
                            <span
                                v-if="day.target_seconds !== null"
                                class="block text-xs leading-tight tabular-nums text-muted-foreground"
                            >
                                of {{ formatDuration(day.target_seconds) }}
                            </span>
                        </td>
                        <td class="p-2 text-right align-top">
                            <span class="text-sm font-semibold tabular-nums">
                                {{ formatDuration(totals.counted_seconds) }}
                            </span>
                            <span
                                v-if="totals.target_seconds !== null"
                                class="block text-xs leading-tight tabular-nums text-muted-foreground"
                            >
                                of {{ formatDuration(totals.target_seconds) }}
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
