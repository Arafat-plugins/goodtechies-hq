<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, GanttChartSquare, Info, Table2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import GanttArrows from '@/Components/Tasks/Gantt/GanttArrows.vue';
import GanttBar from '@/Components/Tasks/Gantt/GanttBar.vue';
import GanttTable from '@/Components/Tasks/Gantt/GanttTable.vue';
import type { GanttDraft, GanttGrab, GanttPayload, GanttRow, GanttTask, GanttZoomOption } from '@/Components/Tasks/Gantt/gantt';
import {
    addDays,
    ganttChanged,
    ganttChartWidth,
    ganttDates,
    ganttDraftGeometry,
    ganttFormatDate,
    ganttGrabWords,
    ganttLayout,
    ganttProjectLabel,
    ganttNextGrab,
    ganttRangeWords,
    ganttScale,
    ganttShift,
    ganttX,
    GANTT_GROUP_HEIGHT,
    GANTT_KEY_HELP,
    GANTT_ROW_HEIGHT,
    toDay,
} from '@/Components/Tasks/Gantt/gantt';
import TaskFilterBar, { taskFiltersActive } from '@/Components/Tasks/TaskFilterBar.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import type { TaskSurface } from '@/Components/Tasks/taskDetail';
import { mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Button } from '@/Components/ui/button';
import { lastFlash } from '@/lib/flashChannel';
import { currentQuery, pushQuery } from '@/lib/tableState';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

/**
 * Tasks → Gantt. Rows grouped by project, bars from start date to due date, dependency arrows,
 * a today line, day / week / month zoom, and the same filter bar the other three views wear.
 *
 * **It draws nothing it was not given.** The window, the columns above the bars, each task's
 * shape and its two dates clipped to that window all arrive from `BuildsGanttPayload`, for the
 * reason the Calendar's window arrives the same way: a timeline that infers which dates it is
 * drawing is a timeline that will one day draw the wrong ones. The only arithmetic here is a
 * day offset times a scale.
 *
 * **Every date write goes through `PUT …/tasks/{task}`.** There is no Gantt endpoint. A drag, a
 * resize and an arrow key are three callers of the same route the detail form calls, so they
 * get `UpdateTaskRequest`'s validation and `TaskService::update()`'s audit row, and a second
 * set of date rules cannot come into existence.
 *
 * **Move-then-confirm, visibly.** A staged change — a pointer drag's live preview, a keyboard
 * edit, or a write in flight — is one `draft`, drawn with a ring and named "not saved yet" in
 * the bar's own accessible name. The instant the server refuses, the draft is dropped and the
 * bar is back where it was, with the server's own sentence spoken. What must never happen is a
 * bar that stays where it was dropped while the server said no, and the draft being the ONLY
 * thing that can put a bar anywhere but its saved position is what makes that impossible.
 *
 * **The keyboard is not a fallback.** See `GanttBar.vue`: each bar is a button, `e` picks what
 * the arrows move, the arrows move it, `Enter` saves and `Escape` puts it back — the same
 * staging the pointer uses. A drag-only Gantt excludes keyboard users outright, and it is the
 * part of a feature like this that is most often skipped.
 *
 * **Below `md` this is not a Gantt at all.** The plan is explicit that the Gantt is desktop-only
 * by design, so a narrow viewport gets the List with a notice rather than a squashed chart.
 */

const props = defineProps<{
    gantt: GanttPayload;
    /** `TaskService::mayPlan()` — the ROLE half of the date-drag rule, resolved on the server. */
    canPlan: boolean;
    surface: TaskSurface;
    filters: TaskFilters;
    zooms: GanttZoomOption[];
    statuses: TaskOption[];
    buckets?: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
    canManageTags?: boolean;
    employees?: TaskNamedRef[];
    searchPlaceholder: string;
    emptyTitle: string;
    emptyDescription: string;
}>();

const filtersActive = computed(() => taskFiltersActive(props.filters));
const filterBar = ref<InstanceType<typeof TaskFilterBar> | null>(null);
const idPrefix = `${props.surface}-gantt`;

/* ---------------------------------------------------------------------- geometry */

const window_ = computed(() => props.gantt.window);
const scale = computed(() => ganttScale(window_.value.zoom));
const chartWidth = computed(() => ganttChartWidth(window_.value));
const layout = computed(() => ganttLayout(props.gantt));

/** A flat list of every task, so a lookup by id does not walk the groups each time. */
const byId = computed(() => {
    const map = new Map<number, GanttTask>();

    for (const row of props.gantt.rows) {
        for (const task of row.tasks) {
            map.set(task.id, task);
        }
    }

    return map;
});

const todayX = computed(() => {
    const today = window_.value.today;

    return toDay(today) < toDay(window_.value.from) || toDay(today) > toDay(window_.value.to)
        ? null
        : ganttX(window_.value, today) + scale.value / 2;
});

/** Which column today falls in, so the heading above it can say so in weight as well as words. */
const todayUnit = computed(() => {
    const today = toDay(window_.value.today);

    return (
        props.gantt.units.find((unit) => toDay(unit.start) <= today && today <= toDay(unit.end))?.key ?? null
    );
});

const heading = computed(
    () => `${ganttFormatDate(window_.value.from)} – ${ganttFormatDate(window_.value.to)}`,
);

const regionLabel = computed(
    () => `Gantt chart, ${heading.value}, ruled by ${window_.value.zoom}. Scrollable sideways.`,
);

/* ------------------------------------------------------------ the window and zoom */

/** Page by the window's own length, so a zoom's step is that zoom's step. */
function shiftWindow(direction: number): void {
    const span = window_.value.days;

    pushQuery({
        date_from: addDays(window_.value.from, direction * span),
        date_to: addDays(window_.value.to, direction * span),
    });
}

/** No window at all is what the server reads as "this zoom's default, around today". */
function goToToday(): void {
    pushQuery({ date_from: null, date_to: null });
}

/**
 * Zoom resets the window.
 *
 * A twenty-eight-day window is four columns at week zoom and one at month zoom, which is a
 * zoom control that appears to do nothing. Each zoom opens on its own span instead, and the
 * address bar says which — `?zoom=` is in the URL because a Gantt at a particular ruling over
 * a particular quarter is a thing somebody bookmarks.
 */
function setZoom(zoom: string): void {
    if (zoom !== window_.value.zoom) {
        pushQuery({ zoom, date_from: null, date_to: null });
    }
}

/* -------------------------------------------------------------- staging a change */

/** The one unsaved change on this screen. See the class docblock. */
const draft = ref<GanttDraft | null>(null);
const busyId = ref<number | null>(null);
const announcement = ref('');

/** What the arrow keys are holding, on the bar that is armed. */
const grab = computed<GanttGrab>(() => draft.value?.grab ?? 'move');

function announce(message: string): void {
    // Re-assigning the same string does not re-announce, and two arrow presses that land on
    // the same dates should still say so.
    announcement.value = announcement.value === message ? `${message} ` : message;
}

function editable(task: GanttTask): boolean {
    // Two halves of one answer, both resolved on the server: the ROLE may change a plan
    // (`TaskService::mayPlan()`), and `TaskPolicy` says this particular task may be updated.
    // Neither is computed here.
    return props.canPlan && task.permissions.can_update;
}

function datesFor(task: GanttTask): { start: string | null; due: string | null } {
    return draft.value?.taskId === task.id
        ? { start: draft.value.start, due: draft.value.due }
        : ganttDates(task);
}

function geometryFor(task: GanttTask) {
    return draft.value?.taskId === task.id ? ganttDraftGeometry(task.gantt, datesFor(task)) : task.gantt;
}

function isStaged(task: GanttTask): boolean {
    return draft.value?.taskId === task.id && ganttChanged(datesFor(task), ganttDates(task));
}

function isArmed(task: GanttTask): boolean {
    return draft.value?.taskId === task.id;
}

function clearDraft(): void {
    draft.value = null;
}

function onCycleGrab(task: GanttTask): void {
    if (!editable(task)) {
        return;
    }

    const next = draft.value?.taskId === task.id ? ganttNextGrab(draft.value.grab) : 'move';
    const dates = datesFor(task);

    draft.value = { taskId: task.id, grab: next, start: dates.start, due: dates.due };

    announce(`${ganttGrabWords(next, geometryFor(task).shape)} ${GANTT_KEY_HELP}`);
}

function onShift(task: GanttTask, payload: { grab: GanttGrab; days: number }): void {
    if (!editable(task)) {
        return;
    }

    const base = datesFor(task);
    const next = ganttShift(task, base, payload.grab, payload.days);

    draft.value = { taskId: task.id, grab: payload.grab, start: next.start, due: next.due };

    const shape = ganttDraftGeometry(task.gantt, next).shape;

    announce(
        ganttChanged(next, ganttDates(task))
            ? `${task.title}, ${ganttRangeWords(shape, next)}. Not saved yet — press Enter to save.`
            : `${task.title}, ${ganttRangeWords(shape, next)}. Back to its saved dates.`,
    );
}

function onCancel(task: GanttTask): void {
    const saved = ganttDates(task);

    clearDraft();
    announce(`Cancelled. ${task.title} is back on ${ganttRangeWords(task.gantt.shape, saved)}.`);
}

function openTask(task: GanttTask): void {
    router.visit(taskRoutes(props.surface, task.id).show);
}

/**
 * Save a staged change.
 *
 * Both dates go every time — including a `null`, which is how a milestone stays a milestone
 * instead of quietly growing the start date a one-day bar would have needed. The server's
 * `due_date >= start_date` rule is what decides whether the shape is legal; the screen does
 * not get a second opinion about it.
 *
 * Two kinds of refusal come back differently and both have to be said. A `TaskStateException`
 * arrives as a flashed error, which this page's `useFlashAsToast()` has already spoken — so it
 * is read out of `lastFlash` for the live region and NOT toasted again (DESIGN.md §5.19). A
 * validation error arrives on `errors` and nothing else on this screen renders it, so this is
 * its one announcement.
 */
function onCommit(task: GanttTask): void {
    if (!editable(task) || busyId.value !== null) {
        return;
    }

    const target = datesFor(task);
    const saved = ganttDates(task);

    if (!ganttChanged(target, saved)) {
        clearDraft();
        announce('Nothing to save.');

        return;
    }

    const shape = ganttDraftGeometry(task.gantt, target).shape;
    let accepted = false;
    let refusal: string | null = null;

    busyId.value = task.id;
    announce(`Saving ${task.title}, ${ganttRangeWords(shape, target)}…`);

    mutateTask(
        'put',
        taskRoutes(props.surface, task.id).update,
        { start_date: target.start, due_date: target.due },
        {
            onAccepted: () => {
                accepted = true;
            },
            onInvalid: (errors) => {
                const first = Object.values(errors)[0];

                refusal = typeof first === 'string' ? first : null;

                if (refusal !== null) {
                    toast.error(refusal);
                }
            },
            onSettled: () => {
                if (accepted) {
                    announce(`Saved. ${task.title} now runs ${ganttRangeWords(shape, target)}.`);

                    return;
                }

                const sentence = refusal ?? lastFlash.error ?? 'The change was not saved.';

                announce(
                    `Not saved. ${sentence} ${task.title} is back on ${ganttRangeWords(task.gantt.shape, saved)}.`,
                );
            },
            onFinish: () => {
                busyId.value = null;
                // Dropped whatever the answer was. Accepted, the payload already carries the
                // new dates; refused, this is what puts the bar back where it was.
                clearDraft();
            },
        },
    );
}

/* ------------------------------------------------------------------ pointer drags */

/**
 * Pointer Events rather than the HTML5 drag API the Board and the Calendar use, and no library
 * either way.
 *
 * The reason is the preview. A Gantt bar is absolutely positioned inside a container that
 * scrolls sideways, and the drop target is a pixel offset rather than a cell — HTML5 drag
 * gives a drag IMAGE and a drop event, which on a month grid is exactly right and here would
 * mean the bar does not move until it lands. `setPointerCapture` keeps the pointer with the
 * bar, the move handler restages the draft on every frame, and the bar the user is dragging is
 * the bar they are looking at.
 */
const dragging = ref<{ taskId: number; grab: GanttGrab; originX: number; origin: { start: string | null; due: string | null } } | null>(
    null,
);

function onDragStart(task: GanttTask, payload: { grab: GanttGrab; clientX: number; pointerId: number }): void {
    if (!editable(task) || busyId.value !== null) {
        return;
    }

    const origin = datesFor(task);

    dragging.value = { taskId: task.id, grab: payload.grab, originX: payload.clientX, origin };
    draft.value = { taskId: task.id, grab: payload.grab, start: origin.start, due: origin.due };

    document.addEventListener('pointermove', onDragMove);
    document.addEventListener('pointerup', onDragEnd);
    document.addEventListener('pointercancel', onDragCancel);
    document.addEventListener('keydown', onDragKey);
}

function onDragMove(event: PointerEvent): void {
    const held = dragging.value;

    if (held === null) {
        return;
    }

    const task = byId.value.get(held.taskId);

    if (task === undefined) {
        return;
    }

    // Recomputed from the ORIGIN every frame rather than accumulated, so a drag that wanders
    // and comes back lands exactly where it started.
    const days = Math.round((event.clientX - held.originX) / scale.value);
    const next = ganttShift(task, held.origin, held.grab, days);

    draft.value = { taskId: task.id, grab: held.grab, start: next.start, due: next.due };
}

function stopDragListeners(): void {
    document.removeEventListener('pointermove', onDragMove);
    document.removeEventListener('pointerup', onDragEnd);
    document.removeEventListener('pointercancel', onDragCancel);
    document.removeEventListener('keydown', onDragKey);
    dragging.value = null;
}

function onDragEnd(): void {
    const held = dragging.value;

    stopDragListeners();

    if (held === null) {
        return;
    }

    const task = byId.value.get(held.taskId);

    if (task === undefined) {
        clearDraft();

        return;
    }

    if (!ganttChanged(datesFor(task), ganttDates(task))) {
        clearDraft();

        return;
    }

    onCommit(task);
}

/** Escape abandons a drag in flight, the same key that abandons a keyboard edit. */
function onDragKey(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        onDragCancel();
    }
}

function onDragCancel(): void {
    const held = dragging.value;

    stopDragListeners();

    if (held !== null) {
        const task = byId.value.get(held.taskId);

        clearDraft();

        if (task !== undefined) {
            announce(`Cancelled. ${task.title} is back on ${ganttRangeWords(task.gantt.shape, ganttDates(task))}.`);
        }
    }
}

onBeforeUnmount(stopDragListeners);

/* --------------------------------------------------------------------- the phone */

/**
 * "On phone width it degrades to the List view with a notice (Gantt is desktop-only by design)."
 *
 * Two mechanisms, deliberately. The notice and the link are CSS — they are what a narrow
 * viewport renders, so there is no flash of a chart nobody can read and no dependency on
 * JavaScript having run. The navigation is a one-shot on mount, so a phone lands on the real
 * List rather than on a page whose whole content is an apology. It does NOT fire on resize: a
 * desktop window being dragged narrower mid-edit must not navigate away from an unsaved change.
 *
 * The filters travel. The window and the zoom do not — they are the Gantt's geometry, and
 * `date_from` on a List is a filter that would silently clip it, which is the same reason
 * `TaskViewSwitcher` drops them.
 */
const GANTT_NARROW = 768;

const listHref = computed(() => {
    const query = { ...currentQuery() };

    delete query.zoom;
    delete query.date_from;
    delete query.date_to;
    delete query.detail;
    delete query.new;

    query.from = 'gantt';

    return `/${props.surface}/tasks?${new URLSearchParams(query).toString()}`;
});

onMounted(() => {
    if (typeof globalThis.innerWidth === 'number' && globalThis.innerWidth < GANTT_NARROW) {
        router.visit(listHref.value, { replace: true });
    }
});

/* ---------------------------------------------------------------------- the table */

const tableOpen = ref(false);

/* ------------------------------------------------------------- naming a project */

/** `ganttProjectLabel`, bound to this screen's surface. The rule itself is in `gantt.ts`. */
function projectLabel(row: GanttRow): string {
    return ganttProjectLabel(row, props.surface);
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <TaskFilterBar
            ref="filterBar"
            :filters="filters"
            :statuses="statuses"
            :buckets="buckets"
            :priorities="priorities"
            :projects="projects"
            :tags="tags"
            :can-manage-tags="canManageTags"
            :employees="employees"
            :placeholder="searchPlaceholder"
            :id-prefix="idPrefix"
            :clear-keeps="['date_from', 'date_to', 'zoom']"
        />

        <!--
            The phone. Not a squashed chart and not an empty state: the notice says why, the
            link goes to the List carrying the filters, and `onMounted` has already sent a real
            phone there.
        -->
        <div class="flex items-start gap-2 rounded-md border bg-muted p-3 text-sm md:hidden">
            <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p class="min-w-0">
                A Gantt needs a wider screen than this one, so it is desktop-only by design.
                <a :href="listHref" class="font-medium underline underline-offset-4">Open the List view</a>
                instead — it keeps the filters you set here.
            </p>
        </div>

        <div class="hidden min-w-0 flex-col gap-4 md:flex">
            <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon-sm"
                        aria-label="Earlier"
                        @click="shiftWindow(-1)"
                    >
                        <ChevronLeft aria-hidden="true" />
                    </Button>
                    <h2 class="min-w-0 text-sm font-medium">{{ heading }}</h2>
                    <Button type="button" variant="outline" size="icon-sm" aria-label="Later" @click="shiftWindow(1)">
                        <ChevronRight aria-hidden="true" />
                    </Button>
                    <Button type="button" variant="outline" size="sm" @click="goToToday">Today</Button>
                </div>

                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <!--
                        Zoom. `aria-pressed` rather than `aria-current`: this is not a different
                        address for a different view, it is a setting on this one — and it goes
                        into the query string so the setting is still shareable.
                    -->
                    <div
                        class="inline-flex items-center gap-1 rounded-md bg-muted p-1"
                        role="group"
                        aria-label="Timeline ruling"
                    >
                        <button
                            v-for="option in zooms"
                            :key="option.value"
                            type="button"
                            :aria-pressed="option.value === gantt.window.zoom"
                            :title="option.description"
                            :class="
                                cn(
                                    'inline-flex h-8 items-center rounded-md px-3 text-sm whitespace-nowrap transition-colors',
                                    'outline-none focus-visible:ring-3 focus-visible:ring-ring',
                                    option.value === gantt.window.zoom
                                        ? 'bg-card font-medium text-foreground shadow-raised'
                                        : 'text-muted-foreground hover:text-foreground',
                                )
                            "
                            @click="setZoom(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        <span class="tabular-nums">{{ gantt.total }}</span>
                        {{ gantt.total === 1 ? 'task' : 'tasks' }} in this window
                    </p>
                </div>
            </div>

            <!-- The tasks this timeline can never draw, said out loud rather than quietly dropped. -->
            <p
                v-if="gantt.unscheduled_count > 0"
                class="flex items-start gap-2 rounded-md border bg-muted p-3 text-xs text-muted-foreground"
            >
                <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                    <span class="tabular-nums">{{ gantt.unscheduled_count }}</span>
                    {{ gantt.unscheduled_count === 1 ? 'task has' : 'tasks have' }} no start or due date, so
                    {{ gantt.unscheduled_count === 1 ? 'it is' : 'they are' }} not on this timeline. The List view
                    shows every task, dated or not.
                </span>
            </p>

            <!-- A clamped window is said, not silently applied. -->
            <p
                v-if="gantt.window.clamped"
                class="flex items-start gap-2 rounded-md border bg-muted p-3 text-xs text-muted-foreground"
            >
                <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                    That window was wider than a {{ gantt.window.zoom }}-ruled timeline draws, so it was cut to
                    {{ ganttFormatDate(gantt.window.from) }} – {{ ganttFormatDate(gantt.window.to) }}. Zoom out to
                    see more at once.
                </span>
            </p>

            <div v-if="gantt.total === 0" class="rounded-xl border bg-card shadow-raised">
                <EmptyState
                    :icon="GanttChartSquare"
                    :variant="filtersActive ? 'filtered' : 'empty'"
                    :title="filtersActive ? 'No tasks match these filters' : emptyTitle"
                    :description="filtersActive ? 'Clear a filter, or widen the window.' : emptyDescription"
                    @clear="filterBar?.clearFilters()"
                />
            </div>

            <template v-else>
                <!--
                    A named, focusable region that scrolls INSIDE itself — decision 8-30 records
                    that a `DataTable` card clips rather than scrolls, so this is deliberately
                    not one. The page never scrolls sideways; this does.
                -->
                <div
                    role="region"
                    :aria-label="regionLabel"
                    tabindex="0"
                    class="overflow-x-auto rounded-xl border bg-card shadow-raised outline-none focus-visible:ring-3 focus-visible:ring-ring"
                >
                    <div class="min-w-max">
                        <!-- The ruler. -->
                        <div class="flex border-b">
                            <div
                                class="sticky left-0 z-30 flex w-56 shrink-0 items-end border-r bg-card px-3 pb-2 text-xs font-medium text-muted-foreground"
                            >
                                Project and task
                            </div>
                            <div class="relative shrink-0" :style="{ width: `${chartWidth}px` }">
                                <!--
                                    The today line's label, on a strip of its own above the
                                    column headings. It is what says the line IS today — a
                                    stripe carrying that on its own would be meaning by
                                    decoration — and it sits above rather than over them
                                    because a chip printed on top of "28 Sep" costs a heading
                                    to name a line.
                                -->
                                <div class="relative h-5">
                                    <span
                                        v-if="todayX !== null"
                                        class="absolute top-0.5 -translate-x-1/2 rounded-sm bg-foreground px-1 text-xs leading-4 font-medium text-background"
                                        :style="{ left: `${todayX}px` }"
                                    >
                                        Today
                                    </span>
                                </div>

                                <div class="flex">
                                    <div
                                        v-for="unit in gantt.units"
                                        :key="unit.key"
                                        :class="
                                            cn(
                                                'shrink-0 overflow-hidden px-1 pb-2 text-center text-xs whitespace-nowrap',
                                                unit.key === todayUnit ? 'font-semibold text-foreground' : 'text-muted-foreground',
                                                unit.boundary ? 'border-l border-border' : 'border-l border-border/40',
                                            )
                                        "
                                        :style="{ width: `${unit.days * scale}px` }"
                                        :title="unit.key === todayUnit ? `${unit.label} — today` : unit.label"
                                    >
                                        {{ unit.short_label }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- The rows. -->
                        <div class="flex">
                            <!-- Names, sticky, so a scrolled timeline still says whose bar it is. -->
                            <div class="sticky left-0 z-20 w-56 shrink-0 border-r bg-card">
                                <template v-for="row in gantt.rows" :key="row.project_id">
                                    <div
                                        class="flex items-center bg-muted/50 px-3 text-xs font-semibold"
                                        :style="{ height: `${GANTT_GROUP_HEIGHT}px` }"
                                    >
                                        <!--
                                            The span, not the flex parent, carries `truncate`:
                                            `text-overflow` applies to a block box, and a bare
                                            text node inside a flex container is an anonymous
                                            item — so the name was cut mid-word with no ellipsis.
                                        -->
                                        <span class="truncate">{{ projectLabel(row) }}</span>
                                    </div>
                                    <div
                                        v-for="task in row.tasks"
                                        :key="task.id"
                                        class="flex items-center border-t px-3 text-xs"
                                        :style="{ height: `${GANTT_ROW_HEIGHT}px` }"
                                    >
                                        <span class="truncate">{{ task.title }}</span>
                                    </div>
                                </template>
                            </div>

                            <!-- The timeline. -->
                            <div
                                class="relative shrink-0"
                                :style="{ width: `${chartWidth}px`, height: `${layout.height}px` }"
                            >
                                <!-- Gridlines, one per column, matching the ruler above exactly. -->
                                <div class="absolute inset-0 flex" aria-hidden="true">
                                    <div
                                        v-for="unit in gantt.units"
                                        :key="unit.key"
                                        :class="
                                            cn('h-full shrink-0', unit.boundary ? 'border-l border-border' : 'border-l border-border/40')
                                        "
                                        :style="{ width: `${unit.days * scale}px` }"
                                    />
                                </div>

                                <!-- Project bands, so a group reads as a group across the chart too. -->
                                <template v-for="(row, index) in gantt.rows" :key="`band-${row.project_id}`">
                                    <div
                                        class="absolute right-0 left-0 bg-muted/50"
                                        aria-hidden="true"
                                        :style="{
                                            top: `${gantt.rows.slice(0, index).reduce((sum, r) => sum + GANTT_GROUP_HEIGHT + r.tasks.length * GANTT_ROW_HEIGHT, 0)}px`,
                                            height: `${GANTT_GROUP_HEIGHT}px`,
                                        }"
                                    />
                                </template>

                                <!-- The today line. Labelled above; decoration here. -->
                                <div
                                    v-if="todayX !== null"
                                    class="absolute top-0 bottom-0 z-10 w-px bg-foreground"
                                    aria-hidden="true"
                                    :style="{ left: `${todayX}px` }"
                                />

                                <GanttArrows
                                    :payload="gantt"
                                    :placements="layout.placements"
                                    :width="chartWidth"
                                    :height="layout.height"
                                    :id-prefix="idPrefix"
                                />

                                <template v-for="row in gantt.rows" :key="`bars-${row.project_id}`">
                                    <div
                                        v-for="task in row.tasks"
                                        :key="task.id"
                                        class="absolute left-0"
                                        :style="{
                                            top: `${layout.placements.get(task.id)?.top ?? 0}px`,
                                            width: `${chartWidth}px`,
                                            height: `${GANTT_ROW_HEIGHT}px`,
                                        }"
                                    >
                                        <GanttBar
                                            :task="task"
                                            :geometry="geometryFor(task)"
                                            :window="gantt.window"
                                            :editable="editable(task)"
                                            :armed="isArmed(task)"
                                            :staged="isStaged(task)"
                                            :grab="grab"
                                            :busy="busyId === task.id"
                                            :described-by="`${idPrefix}-keys`"
                                            @shift="onShift(task, $event)"
                                            @cycle-grab="onCycleGrab(task)"
                                            @commit="onCommit(task)"
                                            @cancel="onCancel(task)"
                                            @open="openTask(task)"
                                            @drag-start="onDragStart(task, $event)"
                                        />
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!--
                    The keys, said once and wired to every bar with `aria-describedby`, plus the
                    reason the handles are inert when they are. One sentence under the chart
                    beats forty tooltips nobody using a keyboard will ever open.
                -->
                <p :id="`${idPrefix}-keys`" class="text-xs text-muted-foreground">
                    <template v-if="canPlan">
                        Drag a bar to move it, or its edges to resize. From the keyboard: {{ GANTT_KEY_HELP }}
                    </template>
                    <template v-else>
                        Only an administrator or a manager may change a task’s dates, so the bars here are read-only.
                        Press Enter on one to open the task.
                    </template>
                </p>

                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-expanded="tableOpen"
                        :aria-controls="`${idPrefix}-table`"
                        @click="tableOpen = !tableOpen"
                    >
                        <Table2 aria-hidden="true" />
                        {{ tableOpen ? 'Hide the table' : 'Show as a table' }}
                    </Button>
                </div>

                <div v-if="tableOpen" :id="`${idPrefix}-table`">
                    <GanttTable :payload="gantt" :surface="surface" />
                </div>
            </template>
        </div>

        <!--
            What a staged change, a save and a refusal say. `polite`, so it waits for a gap
            rather than interrupting, and every message names the task and its dates — an
            announcement of "saved" with nothing saved in it is an announcement nobody can act
            on.
        -->
        <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
    </div>
</template>
