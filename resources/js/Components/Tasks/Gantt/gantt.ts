import type { BoardCard } from '@/Components/Tasks/taskBoard';
import { addDays, fromDay, toDay } from '@/Components/Tasks/TaskCalendar.vue';

/**
 * The Gantt's types and the whole of its arithmetic.
 *
 * **The day maths is the Calendar's, imported and not rewritten.** `toDay` / `fromDay` /
 * `addDays` count UTC days from `YYYY-MM-DD` strings, and the reason they exist is in
 * `TaskCalendar.vue`: the payload's dates are calendar days with no time and no zone, and a
 * local `new Date('2026-09-01')` west of Greenwich is the 31st of August. Two copies of that
 * would be two chances to get it wrong in one repo.
 *
 * What is NOT shared is the shape rule. The Calendar draws a task carrying only one of its two
 * dates as a one-day bar on that date; a Gantt must not, because "a milestone on the due date"
 * and "a one-day bar" are different claims about the work, and the plan names the first one
 * explicitly. So the server sends a `shape` and this module never infers one.
 *
 * Everything here is pure. Nothing reads the DOM, nothing decides permission — `can_plan` and
 * `permissions.can_update` arrive resolved and the screen renders them.
 */

export { addDays, fromDay, toDay };

/* ------------------------------------------------------------------ the payload */

export type GanttZoom = 'day' | 'week' | 'month';

/**
 * Three shapes, because a task has two nullable dates.
 *
 *  - `bar` — both dates. Drawn from one to the other.
 *  - `milestone` — **no start date**. A diamond on the due date; the plan names this case.
 *  - `open_ended` — **no due date**. The plan does not name this case. Drawn as a start mark
 *    with a tail that fades out, because both alternatives lie: a one-day bar says the work
 *    takes a day, and a bar to the edge of the window says it is due at the edge of the
 *    window. Its right-hand handle ADDS a due date rather than moving one.
 *
 *  A task with neither date has no place on a timeline and is not here at all — the payload
 *  counts it as `unscheduled_count` and the screen says so.
 */
export type GanttShape = 'bar' | 'milestone' | 'open_ended';

export interface GanttGeometry {
    shape: GanttShape;
    /** The dates AS STORED, null where they are null — what a move is computed from. */
    start_date: string | null;
    due_date: string | null;
    /** Where the mark sits on the axis: one day for a milestone, two ends for a bar. */
    start: string;
    end: string;
    visible_start: string;
    visible_end: string;
    /** Days from the window's first day to `visible_start`, and the visible length. */
    offset_days: number;
    days: number;
    total_days: number;
    continues_before: boolean;
    continues_after: boolean;
    is_single_day: boolean;
    /** Arrows that ARE drawn — both ends are on this timeline. */
    depends_on_visible: number;
    blocks_visible: number;
    /**
     * Arrows that cannot be drawn because the other end is outside the WINDOW. Counted,
     * because a planner should know there is more; never named, because a count is enough.
     * A dependency whose other end is outside this reader's ACCESS is not in either number.
     */
    depends_on_offscreen: number;
    blocks_offscreen: number;
}

export type GanttTask = BoardCard & { gantt: GanttGeometry };

export interface GanttUnit {
    key: string;
    start: string;
    end: string;
    days: number;
    label: string;
    short_label: string;
    /** Opens a bigger period — the 1st of a month at day zoom, January at month zoom. */
    boundary: boolean;
}

export interface GanttWindow {
    from: string;
    to: string;
    /** Inclusive of both ends. The denominator every left and width is measured against. */
    days: number;
    zoom: GanttZoom;
    /** The asked-for window was wider than this zoom draws, and was cut to fit. */
    clamped: boolean;
    today: string;
}

export interface GanttRow {
    project_id: number;
    project: { id: number; name: string; domain?: string | null } | null;
    tasks: GanttTask[];
}

/** The prerequisite points at the thing waiting for it — the direction a plan is read in. */
export interface GanttEdge {
    from: number;
    to: number;
}

export interface GanttPayload {
    window: GanttWindow;
    units: GanttUnit[];
    rows: GanttRow[];
    edges: GanttEdge[];
    total: number;
    unscheduled_count: number;
}

export interface GanttZoomOption {
    value: GanttZoom;
    label: string;
    description: string;
}

/* ------------------------------------------------------------------- the geometry */

/**
 * Pixels per day, per zoom. The axis is days at every zoom — a unit is a heading and a
 * gridline, never a unit of arithmetic — so one number is the whole of the scale.
 *
 * All three are on the 4 px scale (DESIGN.md §5.4). They are applied as a computed inline
 * width, which is geometry and not spacing: a timeline's length is `days × scale` and there is
 * no utility class for "twenty-eight days wide".
 *
 * They are also chosen so that each zoom's DEFAULT window is a little wider than the content
 * box of a 1440 px screen — 28 × 32, 84 × 12 and about 183 × 8 — so the chart's own scroller
 * is doing something the moment the screen opens, rather than leaving a third of the card
 * blank and the reader wondering whether the timeline is broken.
 */
export const GANTT_PX_PER_DAY: Record<GanttZoom, number> = { day: 32, week: 12, month: 8 };

/** Row heights, shared by the bars' layout and the arrows' SVG so the two cannot disagree. */
export const GANTT_ROW_HEIGHT = 36;
export const GANTT_GROUP_HEIGHT = 32;

/**
 * The narrowest a bar is ever drawn.
 *
 * A one-day bar at month zoom is four pixels: too small to see, far too small to grab, and
 * invisible to anybody looking for it. It is drawn at twelve instead. That overstates its
 * length by a few pixels at the coarsest zoom, which is the right trade — the dates are in the
 * bar's accessible name and in the table, and neither of those is approximate.
 */
export const GANTT_MIN_BAR = 12;

/** A milestone diamond: a fixed size, so zoom cannot shrink it away. */
export const GANTT_MARK_SIZE = 16;

/**
 * How long an open-ended task's tapering tail is drawn — three days, or 60 px, whichever is
 * more.
 *
 * It is a length that means nothing, on purpose: the task has no due date, so any end the
 * chart drew would be a date it does not have. What the length has to do is be big enough to
 * read as a tail rather than as a speck. The first version of this was a 16 px mark and it was
 * invisible at a glance, which is the exact failure the plan warns about — a task that
 * silently vanishes from a planning view is worse than one drawn oddly.
 */
export const GANTT_OPEN_TAIL = 60;
export const GANTT_OPEN_DAYS = 3;

export function ganttScale(zoom: GanttZoom): number {
    return GANTT_PX_PER_DAY[zoom] ?? GANTT_PX_PER_DAY.week;
}

export function ganttChartWidth(window: GanttWindow): number {
    return Math.max(1, window.days) * ganttScale(window.zoom);
}

/** Days from the window's first day to a date — the one conversion between a date and an x. */
export function ganttDayOffset(window: GanttWindow, iso: string): number {
    return toDay(iso) - toDay(window.from);
}

export function ganttX(window: GanttWindow, iso: string): number {
    return ganttDayOffset(window, iso) * ganttScale(window.zoom);
}

/** Where a bar starts and how wide it is, in pixels. */
export function ganttBarBox(window: GanttWindow, geometry: GanttGeometry): { left: number; width: number } {
    const scale = ganttScale(window.zoom);
    const left = geometry.offset_days * scale;

    if (geometry.shape === 'bar') {
        return { left, width: Math.max(GANTT_MIN_BAR, geometry.days * scale) };
    }

    // An open-ended task starts ON its start date and tapers away to the right. It does not
    // end anywhere, so the tail's length is a legibility figure and not a date.
    if (geometry.shape === 'open_ended') {
        return { left, width: Math.max(GANTT_OPEN_TAIL, GANTT_OPEN_DAYS * scale) };
    }

    // A milestone sits on ONE day, centred in that day's column, at a fixed size.
    return { left: left + scale / 2 - GANTT_MARK_SIZE / 2, width: GANTT_MARK_SIZE };
}

/* ------------------------------------------------------- where a task sits vertically */

/** One task's box on the chart, used by the arrows and by nothing else. */
export interface GanttPlacement {
    id: number;
    top: number;
    centreY: number;
    left: number;
    right: number;
}

/**
 * Walk the rows once and record where each bar ended up.
 *
 * The arrows need a y per task and the bars need a top, and computing them twice is how two
 * layouts drift apart by one row height.
 */
export function ganttLayout(payload: GanttPayload): { placements: Map<number, GanttPlacement>; height: number } {
    const placements = new Map<number, GanttPlacement>();
    let y = 0;

    for (const row of payload.rows) {
        y += GANTT_GROUP_HEIGHT;

        for (const task of row.tasks) {
            const box = ganttBarBox(payload.window, task.gantt);

            placements.set(task.id, {
                id: task.id,
                top: y,
                centreY: y + GANTT_ROW_HEIGHT / 2,
                left: box.left,
                right: box.left + box.width,
            });

            y += GANTT_ROW_HEIGHT;
        }
    }

    return { placements, height: y };
}

/**
 * One dependency arrow, as an SVG path.
 *
 * Finish-to-start: out of the prerequisite's right edge, across, into the dependent's left
 * edge. When the dependent starts BEFORE its prerequisite finishes — a plan that does not hold
 * together, which is exactly the thing a Gantt is read to find — the path detours below both
 * rows rather than drawing a line backwards through the bars.
 */
export function ganttEdgePath(from: GanttPlacement, to: GanttPlacement): string {
    const sx = from.right;
    const sy = from.centreY;
    const tx = to.left;
    const ty = to.centreY;
    const out = 8;
    const approach = 7;

    if (tx - sx >= out + approach) {
        return `M ${sx} ${sy} H ${sx + out} V ${ty} H ${tx - approach}`;
    }

    const detour = Math.max(sy, ty) + GANTT_ROW_HEIGHT / 2 - 6;

    return `M ${sx} ${sy} H ${sx + out} V ${detour} H ${tx - out - approach} V ${ty} H ${tx - approach}`;
}

/* --------------------------------------------------------------- moving the dates */

/** What a drag or an arrow key has hold of. */
export type GanttGrab = 'move' | 'start' | 'end';

/**
 * A staged, unsaved change to one task's dates.
 *
 * One concept serves three things — the live preview under a pointer drag, the keyboard's
 * staged edit, and the in-flight optimism while the server answers — so there is one place
 * that says where a bar is drawn when it is not where it is saved, and one place to clear.
 */
export interface GanttDraft {
    taskId: number;
    grab: GanttGrab;
    start: string | null;
    due: string | null;
}

/** The dates a task is saved with, which every move is computed from. */
export function ganttDates(task: GanttTask): { start: string | null; due: string | null } {
    return { start: task.gantt.start_date, due: task.gantt.due_date };
}

/**
 * Apply a shift of `days` to whatever is grabbed, and return the new pair.
 *
 * The rules that matter, all of them here rather than in a pointer handler and again in a key
 * handler:
 *
 *  - **`move` shifts both ends**, keeping the length. A milestone has one date and moving it
 *    moves that; it does NOT grow a start date, or the first drag would silently stop it being
 *    a milestone.
 *  - **A grabbed end never crosses the other.** It clamps to a single day instead of producing
 *    a negative span. The server would refuse one — `due_date` is `after_or_equal:start_date` —
 *    and a refusal the screen could have avoided is a round trip spent on a shape nobody meant.
 *  - **An open-ended task's `end` handle ADDS a due date**, starting at its start date. That is
 *    the one move here that changes a task's shape, and it is the useful one: the answer to "no
 *    due date" is to give it one.
 *  - **A milestone's `start` handle ADDS a start date**, turning the diamond into a bar ending
 *    on the due date. The same move in the other direction.
 */
export function ganttShift(
    task: GanttTask,
    from: { start: string | null; due: string | null },
    grab: GanttGrab,
    days: number,
): { start: string | null; due: string | null } {
    const start = from.start;
    const due = from.due;

    if (grab === 'move') {
        return {
            start: start === null ? null : addDays(start, days),
            due: due === null ? null : addDays(due, days),
        };
    }

    if (grab === 'start') {
        // A milestone has no start date; grabbing its start handle gives it one, anchored on
        // the due date so the first press makes a one-day bar rather than a negative one.
        const anchor = start ?? due;

        if (anchor === null) {
            return { start, due };
        }

        const next = addDays(anchor, days);

        return { start: due !== null && toDay(next) > toDay(due) ? due : next, due };
    }

    // `end`. An open-ended task has no due date; grabbing its end handle gives it one.
    const anchor = due ?? start;

    if (anchor === null) {
        return { start, due };
    }

    const next = addDays(anchor, days);

    return { start, due: start !== null && toDay(next) < toDay(start) ? start : next };
}

/** Did a staged change actually change anything? */
export function ganttChanged(
    a: { start: string | null; due: string | null },
    b: { start: string | null; due: string | null },
): boolean {
    return a.start !== b.start || a.due !== b.due;
}

/**
 * The geometry a staged change draws at, computed the same way the server computes a saved
 * one — so a bar under the pointer and the same bar a moment after it is saved sit in exactly
 * the same place, and nothing jumps on the way through.
 */
export function ganttDraftGeometry(base: GanttGeometry, dates: { start: string | null; due: string | null }): GanttGeometry {
    const { start, due } = dates;

    if (start === null && due === null) {
        return base;
    }

    const shape: GanttShape = start === null ? 'milestone' : due === null ? 'open_ended' : 'bar';
    let anchorStart = start ?? (due as string);
    let anchorEnd = due ?? (start as string);

    if (toDay(anchorEnd) < toDay(anchorStart)) {
        [anchorStart, anchorEnd] = [anchorEnd, anchorStart];
    }

    return {
        ...base,
        shape,
        start_date: start,
        due_date: due,
        start: anchorStart,
        end: anchorEnd,
        visible_start: anchorStart,
        visible_end: anchorEnd,
        // Measured against the same window origin the server measured against; `base` carries
        // the offset of the SAVED bar, so this is recomputed rather than adjusted.
        offset_days: base.offset_days + (toDay(anchorStart) - toDay(base.visible_start)),
        days: toDay(anchorEnd) - toDay(anchorStart) + 1,
        total_days: toDay(anchorEnd) - toDay(anchorStart) + 1,
        continues_before: false,
        continues_after: false,
        is_single_day: anchorStart === anchorEnd,
    };
}

/**
 * What a row's group heading calls its project — the same rule `TaskBoardCard` uses, not a
 * second one.
 *
 * The Admin surface prints the NAME and the employee surface the DOMAIN, because that is what
 * `ProjectResource` sends each of them and what each of them knows a project by. It matters
 * more on a timeline than on a card: one domain carries several engagements, so a chart that
 * headed its groups with domains showed `buffalomodular.com` three times with different work
 * under each and no way to tell which was which.
 */
export function ganttProjectLabel(row: GanttRow, surface: 'admin' | 'employee'): string {
    const project = row.project;

    if (project === null) {
        return 'Project';
    }

    return surface === 'employee' ? (project.domain ?? project.name) : project.name;
}

/* ----------------------------------------------------------------------- the words */

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'UTC' });

export function ganttFormatDate(iso: string): string {
    return DATE.format(new Date(`${iso}T00:00:00Z`));
}

/**
 * What a bar says — to a screen reader through its accessible name, and to the live region
 * after every keystroke that moves it.
 *
 * The status is in the sentence and not only in the fill: DESIGN.md §5.6, and §1.4 is the
 * measurement — two of the eight statuses are ΔE 0.16 apart under deuteranopia, so a coloured
 * rectangle is not a status. The shape is in the sentence for the same reason a diamond is
 * not a word.
 */
export function ganttSentence(
    title: string,
    statusLabel: string,
    shape: GanttShape,
    dates: { start: string | null; due: string | null },
): string {
    const { start, due } = dates;

    if (shape === 'milestone' || (start === null && due !== null)) {
        return `${title}, milestone on ${ganttFormatDate(due as string)}, ${statusLabel}`;
    }

    if (shape === 'open_ended' || (due === null && start !== null)) {
        return `${title}, starts ${ganttFormatDate(start as string)}, no due date, ${statusLabel}`;
    }

    if (start === null || due === null) {
        return `${title}, no dates, ${statusLabel}`;
    }

    if (start === due) {
        return `${title}, ${ganttFormatDate(start)}, ${statusLabel}`;
    }

    return `${title}, ${ganttFormatDate(start)} to ${ganttFormatDate(due)}, ${statusLabel}`;
}

/** The range on its own, for the live region's shorter sentences and for the table. */
export function ganttRangeWords(shape: GanttShape, dates: { start: string | null; due: string | null }): string {
    const { start, due } = dates;

    if (shape === 'milestone' || (start === null && due !== null)) {
        return `a milestone on ${ganttFormatDate(due as string)}`;
    }

    if (shape === 'open_ended' || (due === null && start !== null)) {
        return `starting ${ganttFormatDate(start as string)} with no due date`;
    }

    if (start === null || due === null) {
        return 'undated';
    }

    return start === due ? ganttFormatDate(start) : `${ganttFormatDate(start)} to ${ganttFormatDate(due)}`;
}

/** What the `e` key just picked up, said in full because "start" alone is not an instruction. */
export function ganttGrabWords(grab: GanttGrab, shape: GanttShape): string {
    if (shape === 'milestone' && grab === 'move') {
        return 'Moving the milestone date.';
    }

    if (grab === 'move') {
        return 'Moving the whole bar.';
    }

    if (grab === 'start') {
        return shape === 'milestone'
            ? 'Adding a start date. This turns the milestone into a bar.'
            : 'Moving the start date.';
    }

    return shape === 'open_ended' ? 'Adding a due date.' : 'Moving the due date.';
}

/** The keys, said once, in the order somebody would try them. */
export const GANTT_KEY_HELP =
    'Left and right arrows move it by a day, with Shift by a week. ' +
    'E changes what the arrows move: the whole bar, the start date, the due date. ' +
    'Enter saves, Escape cancels.';

/** The next thing `e` picks up. Milestones and open-ended marks cycle through all three too — both handles ADD the missing date. */
export function ganttNextGrab(grab: GanttGrab): GanttGrab {
    return grab === 'move' ? 'start' : grab === 'start' ? 'end' : 'move';
}

/** A week is seven days at every zoom: a Shift press that meant a month at one ruling and a day at another would be a Shift press nobody could predict. */
export const GANTT_STEP = 1;
export const GANTT_BIG_STEP = 7;
