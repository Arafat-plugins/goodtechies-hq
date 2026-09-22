<script lang="ts">
import type { BoardCard } from '@/Components/Tasks/taskBoard';

/**
 * The Calendar's payload.
 *
 * `span` rides beside the task rather than inside it, because it is the geometry of **this
 * window** and not a fact about the task: the same task is in August's payload clipped one way
 * and September's clipped another. Nothing here is recomputed — `visible_start` / `visible_end`
 * arrive already clipped to the window, and `continues_before` / `continues_after` already say
 * which edge the bar runs off.
 */
export interface CalendarSpan {
    start: string;
    end: string;
    visible_start: string;
    visible_end: string;
    days: number;
    total_days: number;
    continues_before: boolean;
    continues_after: boolean;
    is_single_day: boolean;
}

export type CalendarTask = BoardCard & { span: CalendarSpan };

/** The window the server actually used, which is not always the one that was asked for. */
export interface CalendarWindow {
    from: string;
    to: string;
    days: number;
    /** Set only when the window is exactly one calendar month — what a month grid's heading names. */
    month: string | null;
}

export interface CalendarPayload {
    window: CalendarWindow;
    total: number;
    /** Tasks with no date at all. This grid can never show them, so it says so. */
    unscheduled_count: number;
    tasks: CalendarTask[];
}

/* ------------------------------------------------------------------ day maths */

/**
 * Plain `YYYY-MM-DD` arithmetic, in UTC.
 *
 * UTC on purpose: the payload's dates are calendar days with no time and no zone, and a local
 * `new Date('2026-09-01')` west of Greenwich is the 31st of August. The grid draws days, so it
 * counts days.
 */
export function toDay(iso: string): number {
    const [year, month, day] = iso.split('-').map(Number);

    return Date.UTC(year ?? 1970, (month ?? 1) - 1, day ?? 1) / 86_400_000;
}

export function fromDay(day: number): string {
    return new Date(day * 86_400_000).toISOString().slice(0, 10);
}

export function addDays(iso: string, days: number): string {
    return fromDay(toDay(iso) + days);
}

/** Monday-start, matching the `en-GB` formatting the rest of the app uses. */
export function weekdayIndex(day: number): number {
    // 1970-01-01 (day 0) was a Thursday, which is index 3 in a Monday-first week.
    return (((day + 3) % 7) + 7) % 7;
}
</script>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { statusToneClass } from '@/Components/StatusBadge.vue';
import TaskFilterBar, { taskFiltersActive } from '@/Components/Tasks/TaskFilterBar.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import type { TaskSurface } from '@/Components/Tasks/taskDetail';
import { mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { pushQuery } from '@/lib/tableState';
import { cn } from '@/lib/utils';

/**
 * A month grid drawn from the window the payload gives it.
 *
 * The window is the server's answer, never inferred from the tasks that happened to arrive — a
 * grid that guessed would be wrong on the first empty month. Each bar is laid out from its
 * `span`: where it starts and ends inside the window, and which edge it runs off.
 *
 * **Dates are a plan change, and the plan is Admin/Manager only.** `can_plan` is the same answer
 * `UpdateTaskRequest` gives when it makes `start_date` and `due_date` `prohibited`, from one
 * definition — so a handle is never live for somebody the write would refuse. For everybody else
 * the handles are drawn and visibly disabled rather than absent: a bar with no grab points at
 * all reads as a bug, and the reason is said once under the grid.
 *
 * Nothing moves optimistically here. A date write is one field on one task, not a card that has
 * to be somewhere while the server thinks: the bar dims until the server answers and the grid is
 * then redrawn from the payload, so what is on screen is always what is saved.
 */

const props = defineProps<{
    calendar: CalendarPayload;
    canPlan: boolean;
    surface: TaskSurface;
    filters: TaskFilters;
    statuses: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
    employees?: TaskNamedRef[];
    searchPlaceholder: string;
    emptyTitle: string;
    emptyDescription: string;
}>();

const filtersActive = computed(() => taskFiltersActive(props.filters));
const filterBar = ref<InstanceType<typeof TaskFilterBar> | null>(null);

/* ------------------------------------------------------------------ the heading */

const MONTH = new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });
const RANGE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'UTC' });

function asDate(iso: string): Date {
    return new Date(`${iso}T00:00:00Z`);
}

/** The month, when the window is one — otherwise the two ends, because that is what it is. */
const heading = computed(() => {
    const window = props.calendar.window;

    return window.month === null
        ? `${RANGE.format(asDate(window.from))} – ${RANGE.format(asDate(window.to))}`
        : MONTH.format(asDate(window.from));
});

/** A whole month either side, written into the query string so the view stays a URL. */
function goToMonth(offset: number): void {
    const from = asDate(props.calendar.window.from);
    const first = new Date(Date.UTC(from.getUTCFullYear(), from.getUTCMonth() + offset, 1));
    const last = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0));

    pushQuery({
        date_from: first.toISOString().slice(0, 10),
        date_to: last.toISOString().slice(0, 10),
    });
}

function goToThisMonth(): void {
    // No window at all is what `TaskService::window()` reads as "the month we are in".
    pushQuery({ date_from: null, date_to: null });
}

/* --------------------------------------------------------------------- the grid */

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

interface GridDay {
    iso: string;
    /** Outside the window the payload named — the grid still draws the week whole. */
    outside: boolean;
    number: number;
}

interface Segment {
    task: CalendarTask;
    /** 1–7 within this week row, and how many of its columns the bar covers. */
    column: number;
    width: number;
    /** The first day this segment covers, which is what a body drag measures its grab from. */
    startIso: string;
    /** The bar runs on past this end — off the window, or into the next week row. */
    openLeft: boolean;
    openRight: boolean;
    /** This segment holds the span's real start / end, so it carries that resize handle. */
    ownsStart: boolean;
    ownsEnd: boolean;
}

interface WeekRow {
    key: string;
    days: GridDay[];
    lanes: Segment[][];
}

/**
 * The weeks this window covers, padded to whole Monday-to-Sunday rows.
 *
 * The padding days are drawn and muted rather than left blank: a month grid missing its first
 * three cells is a grid whose columns no longer line up with their weekday headings.
 */
const weeks = computed<WeekRow[]>(() => {
    const window = props.calendar.window;
    const first = toDay(window.from);
    const last = toDay(window.to);
    const gridStart = first - weekdayIndex(first);
    const gridEnd = last + (6 - weekdayIndex(last));
    const rows: WeekRow[] = [];

    for (let start = gridStart; start <= gridEnd; start += 7) {
        const days: GridDay[] = [];

        for (let offset = 0; offset < 7; offset += 1) {
            const day = start + offset;
            const iso = fromDay(day);

            days.push({ iso, outside: day < first || day > last, number: Number(iso.slice(8)) });
        }

        rows.push({ key: fromDay(start), days, lanes: pack(start, start + 6) });
    }

    return rows;
});

/**
 * Greedy lane packing for one week row.
 *
 * The payload arrives already ordered for this — earliest visible day, then the longest bar,
 * then id — so two identical spans never swap lanes between two requests.
 */
function pack(weekStart: number, weekEnd: number): Segment[][] {
    const lanes: Segment[][] = [];

    for (const task of props.calendar.tasks) {
        const span = task.span;
        const visibleStart = toDay(span.visible_start);
        const visibleEnd = toDay(span.visible_end);
        const from = Math.max(visibleStart, weekStart);
        const to = Math.min(visibleEnd, weekEnd);

        if (from > to) {
            continue;
        }

        const segment: Segment = {
            task,
            column: from - weekStart + 1,
            width: to - from + 1,
            startIso: fromDay(from),
            // Two ways a bar is open at an edge: the span runs off the window (the server's
            // `continues_*`), or it simply carries on into the next week row.
            openLeft: span.continues_before || from > visibleStart,
            openRight: span.continues_after || to < visibleEnd,
            ownsStart: !span.continues_before && from === visibleStart,
            ownsEnd: !span.continues_after && to === visibleEnd,
        };

        const lane = lanes.find((row) => row.every((other) => other.column + other.width - 1 < segment.column));

        if (lane === undefined) {
            lanes.push([segment]);
        } else {
            lane.push(segment);
        }
    }

    return lanes;
}

/*
 * Written out in full so Tailwind's scanner keeps them: a class built by concatenation is a
 * class that never reaches the stylesheet.
 */
const COL_START = [
    '',
    'col-start-1',
    'col-start-2',
    'col-start-3',
    'col-start-4',
    'col-start-5',
    'col-start-6',
    'col-start-7',
];
const COL_SPAN = ['', 'col-span-1', 'col-span-2', 'col-span-3', 'col-span-4', 'col-span-5', 'col-span-6', 'col-span-7'];

/** What the bar says to a screen reader: what it is, where it stands, and how far it runs. */
function barLabel(task: CalendarTask): string {
    const span = task.span;
    const range = span.is_single_day
        ? RANGE.format(asDate(span.start))
        : `${RANGE.format(asDate(span.start))} to ${RANGE.format(asDate(span.end))}`;

    return `${task.title} — ${task.status_label}, ${range}`;
}

const today = new Date().toISOString().slice(0, 10);

/* ---------------------------------------------------------------- moving a date */

/** Which task is being written, so its bar says so and a second drag cannot race it. */
const busyId = ref<number | null>(null);

type Grab = 'move' | 'start' | 'end';

const drag = ref<{ task: CalendarTask; grab: Grab; anchor: string } | null>(null);
const hoverDay = ref<string | null>(null);

/**
 * Start a date drag.
 *
 * For a body drag the anchor is the day **under the pointer** when the bar was picked up, so a
 * bar grabbed in the middle moves by the distance the pointer moved rather than snapping its
 * own start to the drop day. For a handle the anchor is not used — that end simply lands where
 * it is dropped.
 */
function onBarDragStart(segment: Segment, grab: Grab, event: DragEvent): void {
    if (!props.canPlan || busyId.value !== null) {
        event.preventDefault();

        return;
    }

    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const within =
        box.width === 0
            ? 0
            : Math.min(
                  segment.width - 1,
                  Math.max(0, Math.floor(((event.clientX - box.left) / box.width) * segment.width)),
              );

    drag.value = { task: segment.task, grab, anchor: addDays(segment.startIso, grab === 'move' ? within : 0) };

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(segment.task.id));
    }
}

function endDrag(): void {
    drag.value = null;
    hoverDay.value = null;
}

/**
 * Which day the pointer is over, measured across the whole week row.
 *
 * Measured rather than read off a cell's own handler because the bars sit on top of the cells:
 * a drop that happened to land on another bar would otherwise reach no day at all.
 */
function dayUnder(week: WeekRow, event: DragEvent): GridDay | null {
    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();

    if (box.width === 0) {
        return null;
    }

    const index = Math.min(6, Math.max(0, Math.floor(((event.clientX - box.left) / box.width) * 7)));

    return week.days[index] ?? null;
}

function onRowDragOver(week: WeekRow, event: DragEvent): void {
    if (drag.value === null) {
        return;
    }

    const day = dayUnder(week, event);

    if (day === null || day.outside) {
        return;
    }

    // Without this the browser's default is "no drop here" and `drop` never fires.
    event.preventDefault();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }

    hoverDay.value = day.iso;
}

/**
 * Land the drag.
 *
 * Both dates are sent every time, so the server's own `due_date >= start_date` rule is what
 * decides whether the shape is legal — the screen does not get a second opinion about it. A
 * resize that would cross the other end is clamped to a single day rather than sent as a
 * negative span.
 */
function onRowDrop(week: WeekRow, event: DragEvent): void {
    event.preventDefault();

    const held = drag.value;
    const day = dayUnder(week, event);

    endDrag();

    if (held === null || day === null || day.outside || !props.canPlan) {
        return;
    }

    const span = held.task.span;
    let start = span.start;
    let end = span.end;

    if (held.grab === 'move') {
        const shift = toDay(day.iso) - toDay(held.anchor);

        start = addDays(span.start, shift);
        end = addDays(span.end, shift);
    } else if (held.grab === 'start') {
        start = toDay(day.iso) > toDay(span.end) ? span.end : day.iso;
    } else {
        end = toDay(day.iso) < toDay(span.start) ? span.start : day.iso;
    }

    if (start === span.start && end === span.end) {
        return;
    }

    busyId.value = held.task.id;

    mutateTask(
        'put',
        taskRoutes(props.surface, held.task.id).update,
        { start_date: start, due_date: end },
        {
            onFinish: () => {
                busyId.value = null;
            },
        },
    );
}

function openTask(task: CalendarTask): void {
    router.visit(taskRoutes(props.surface, task.id).show);
}

const handleTitle = computed(() =>
    props.canPlan ? null : 'Only an administrator or a manager may change a task’s dates.',
);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <TaskFilterBar
            ref="filterBar"
            :filters="filters"
            :statuses="statuses"
            :priorities="priorities"
            :projects="projects"
            :tags="tags"
            :employees="employees"
            :placeholder="searchPlaceholder"
            :id-prefix="`${surface}-calendar`"
            :clear-keeps="['date_from', 'date_to']"
        />

        <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="icon-sm"
                    aria-label="Previous month"
                    @click="goToMonth(-1)"
                >
                    <ChevronLeft aria-hidden="true" />
                </Button>
                <h2 class="min-w-0 text-sm font-medium">{{ heading }}</h2>
                <Button type="button" variant="outline" size="icon-sm" aria-label="Next month" @click="goToMonth(1)">
                    <ChevronRight aria-hidden="true" />
                </Button>
                <Button type="button" variant="outline" size="sm" @click="goToThisMonth">This month</Button>
            </div>

            <p class="text-xs text-muted-foreground">
                <span class="tabular-nums">{{ calendar.total }}</span>
                {{ calendar.total === 1 ? 'task' : 'tasks' }} in this window
            </p>
        </div>

        <!--
            The tasks this grid can never draw, said out loud rather than quietly dropped:
            `unscheduled_count` is the whole reason the payload carries it.
        -->
        <p
            v-if="calendar.unscheduled_count > 0"
            class="flex items-start gap-2 rounded-md border bg-muted p-3 text-xs text-muted-foreground"
        >
            <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                <span class="tabular-nums">{{ calendar.unscheduled_count }}</span>
                {{ calendar.unscheduled_count === 1 ? 'task has' : 'tasks have' }} no start or due date, so
                {{ calendar.unscheduled_count === 1 ? 'it is' : 'they are' }} not on this grid. The List view shows
                every task, dated or not.
            </span>
        </p>

        <!--
            Nothing in this window. The grid is still drawn below: a month with no tasks in it
            is a month, and a screen that replaced it with a medallion would leave the reader
            unable to page to the next one.
        -->
        <div v-if="calendar.tasks.length === 0" class="rounded-xl border bg-card shadow-raised">
            <EmptyState
                :icon="CalendarDays"
                :variant="filtersActive ? 'filtered' : 'empty'"
                :title="filtersActive ? 'No tasks match these filters' : emptyTitle"
                :description="filtersActive ? 'Clear a filter, or widen the search.' : emptyDescription"
                @clear="filterBar?.clearFilters()"
            />
        </div>

        <!--
            Seven columns do not fit on a phone at a size anybody can read, so the grid keeps a
            floor and its container scrolls sideways — the same answer the Board gives, rather
            than a second layout that would have to be kept in step with this one. The page
            itself does not scroll sideways.
        -->
        <div class="-mx-4 overflow-x-auto px-4 pb-2 md:-mx-6 md:px-6" :aria-busy="busyId !== null || undefined">
            <div class="min-w-2xl overflow-hidden rounded-xl border bg-card shadow-raised">
                <div class="grid grid-cols-7 border-b bg-muted">
                    <div
                        v-for="weekday in WEEKDAYS"
                        :key="weekday"
                        class="px-2 py-1.5 text-center text-xs font-medium text-muted-foreground"
                    >
                        {{ weekday }}
                    </div>
                </div>

                <!--
                    One row per week. The day cells are a background layer so the bars can span
                    them; the numbers and the lanes are in normal flow on top, which is what
                    makes the row grow to fit however many lanes a busy week needs.
                -->
                <div
                    v-for="week in weeks"
                    :key="week.key"
                    :data-week="week.key"
                    class="relative min-h-24 border-b last:border-b-0"
                    @dragover="onRowDragOver(week, $event)"
                    @drop="onRowDrop(week, $event)"
                >
                    <div class="absolute inset-0 grid grid-cols-7" aria-hidden="true">
                        <div
                            v-for="day in week.days"
                            :key="day.iso"
                            :class="
                                cn(
                                    'border-r last:border-r-0',
                                    day.outside && 'bg-muted',
                                    hoverDay === day.iso && 'bg-accent',
                                )
                            "
                        />
                    </div>

                    <div class="relative grid grid-cols-7 p-1">
                        <div v-for="day in week.days" :key="day.iso" class="min-w-0 text-center">
                            <span
                                :class="
                                    cn(
                                        'inline-flex size-6 items-center justify-center rounded-full text-xs tabular-nums',
                                        day.outside ? 'text-muted-foreground' : 'text-foreground',
                                        // Today is named as well as ringed: a ring on its own
                                        // is colour carrying meaning alone (DESIGN.md §5.6).
                                        day.iso === today && 'border border-ring font-medium',
                                    )
                                "
                            >
                                {{ day.number }}
                                <span v-if="day.iso === today" class="sr-only">(today)</span>
                            </span>
                        </div>
                    </div>

                    <div class="relative flex flex-col gap-1 px-1 pb-2">
                        <div v-for="(lane, index) in week.lanes" :key="index" class="grid grid-cols-7 gap-px">
                            <div
                                v-for="segment in lane"
                                :key="segment.task.id"
                                :class="cn('flex min-w-0', COL_START[segment.column], COL_SPAN[segment.width])"
                            >
                                <div
                                    :draggable="canPlan && busyId === null"
                                    :data-task-id="segment.task.id"
                                    :aria-busy="busyId === segment.task.id || undefined"
                                    :class="
                                        cn(
                                            'flex h-6 w-full min-w-0 items-center gap-1 border px-1 text-xs',
                                            statusToneClass(segment.task.status_tone),
                                            segment.openLeft ? 'rounded-l-none border-l-0' : 'rounded-l-md',
                                            segment.openRight ? 'rounded-r-none border-r-0' : 'rounded-r-md',
                                            canPlan && busyId === null && 'cursor-grab active:cursor-grabbing',
                                            busyId === segment.task.id && 'opacity-60',
                                        )
                                    "
                                    @dragstart="onBarDragStart(segment, 'move', $event)"
                                    @dragend="endDrag"
                                >
                                    <!--
                                        The start handle, on the segment that owns the span's
                                        start. Drawn and visibly disabled rather than absent
                                        when the viewer may not plan: `can_plan` is the same
                                        answer `UpdateTaskRequest` gives, and a bar with no
                                        grab points at all reads as a bug rather than a rule.
                                    -->
                                    <span
                                        v-if="segment.ownsStart"
                                        :draggable="canPlan && busyId === null"
                                        :aria-disabled="canPlan ? undefined : true"
                                        :title="handleTitle ?? 'Drag to change the start date'"
                                        :class="
                                            cn(
                                                'h-3 w-1 shrink-0 rounded-full bg-current',
                                                canPlan ? 'cursor-ew-resize opacity-70' : 'cursor-not-allowed opacity-30',
                                            )
                                        "
                                        @dragstart.stop="onBarDragStart(segment, 'start', $event)"
                                    />
                                    <ChevronLeft
                                        v-else-if="segment.openLeft"
                                        class="size-3 shrink-0"
                                        aria-hidden="true"
                                    />

                                    <button
                                        type="button"
                                        draggable="false"
                                        class="min-w-0 flex-1 truncate rounded-sm text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                                        :aria-label="barLabel(segment.task)"
                                        @click="openTask(segment.task)"
                                    >
                                        <span aria-hidden="true">{{ segment.task.title }}</span>
                                    </button>

                                    <span
                                        v-if="segment.ownsEnd"
                                        :draggable="canPlan && busyId === null"
                                        :aria-disabled="canPlan ? undefined : true"
                                        :title="handleTitle ?? 'Drag to change the due date'"
                                        :class="
                                            cn(
                                                'h-3 w-1 shrink-0 rounded-full bg-current',
                                                canPlan ? 'cursor-ew-resize opacity-70' : 'cursor-not-allowed opacity-30',
                                            )
                                        "
                                        @dragstart.stop="onBarDragStart(segment, 'end', $event)"
                                    />
                                    <ChevronRight
                                        v-else-if="segment.openRight"
                                        class="size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-xs text-muted-foreground">
            <template v-if="canPlan">
                Drag a bar to move it, or either end to change one date. A bar with a chevron runs on past this
                window. Dates can also be typed on the task itself.
            </template>
            <template v-else>
                Dates are set by an administrator or a manager, so the handles on a bar are disabled here. A bar
                with a chevron runs on past this window.
            </template>
        </p>
    </div>
</template>
