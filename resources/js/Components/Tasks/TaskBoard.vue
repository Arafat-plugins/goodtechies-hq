<script setup lang="ts">
import { KanbanSquare } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TaskBoardCard from '@/Components/Tasks/TaskBoardCard.vue';
import TaskFilterBar, { taskFiltersActive } from '@/Components/Tasks/TaskFilterBar.vue';
import type { TaskFilters, TaskNamedRef, TaskOption, TaskTag } from '@/Components/Tasks/TaskList.vue';
import TaskStatusActions from '@/Components/Tasks/TaskStatusActions.vue';
import type { BoardCard, BoardColumn, BoardPayload, TransitionMap } from '@/Components/Tasks/taskBoard';
import { asDetail, cloneColumns, moveCard, movesFor, neighbours } from '@/Components/Tasks/taskBoard';
import type { TaskSurface } from '@/Components/Tasks/taskDetail';
import { mutateTask, taskRoutes } from '@/Components/Tasks/taskDetail';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

/**
 * The Board: eight status columns, drag between them, drag to reorder inside one.
 *
 * **Built on the native HTML5 drag-and-drop API**, because `package.json` has no drag library
 * and a dependency is not this brief's to add. The board keeps its own copy of the columns so
 * a card can move the instant it is dropped, and keeps the copy it moved *from* so it can put
 * the card back.
 *
 * ## The rule, and which half of it lives here
 *
 * `transitions` is the ROLE half — `{from: [allowed_to]}` from `TaskService::transitionsFor()`.
 * It is what makes the rule legible before the drag: a column this role could never drop into
 * is dimmed and says so the moment a card is picked up, rather than accepting the drop and
 * then undoing it.
 *
 * The TASK half — am I assigned to this one, am I this project's reviewer — is `TaskPolicy`'s
 * and is checked on the drop. **So a drop this screen allowed can still come back refused.**
 * When it does, two things happen together: the server's own sentence is spoken (the page
 * claims the flash channel, DESIGN.md §5.19) and the card goes back where it came from. A card
 * that stayed in the new column after a refusal would be a lie about saved state.
 *
 * ## In review does not commit before the summary exists
 *
 * Dragging into In review opens the work-summary form — `TaskStatusActions`, the one slice 2
 * built, mounted here `variant="headless"` so there is one copy of "what does this move have
 * to collect first" and not two. Cancelling that dialog writes nothing and returns the card.
 *
 * ## Keyboard
 *
 * Every move a mouse can make is on the card's ⋯ menu: *Move to* for a column change and
 * *Move up* / *Move down* for the order inside one. Both go down the same two code paths the
 * drag does, so they cannot drift apart from it.
 *
 * ## How it scrolls, and why that is the shape it is
 *
 * From `md` up the board is a **fixed region under the page header**: it is exactly as tall as
 * what is left of the viewport, each lane scrolls its own cards vertically, and the strip
 * scrolls sideways with its scrollbar at the bottom of that region. The page itself does not
 * scroll. Before this, the lanes grew as tall as their contents — twenty-five cards made the
 * document 1858 px on a 900 px viewport — and the strip's horizontal scrollbar sat at the very
 * bottom of all of it, roughly 900 px below the fold. The one control for moving the board
 * sideways could only be reached by first scrolling past every card, which is the client's
 * report: *"bottom scrollbar is below huge."*
 *
 * Below `md` nothing changes: a phone gets one snapped column at a time and the wrapped status
 * rail that names and counts every lane, and the page scrolls as it always did. A region that
 * short on a phone would hold barely one card, and a touch swipe never needed the scrollbar.
 */

const props = defineProps<{
    board: BoardPayload;
    transitions: TransitionMap;
    filters: TaskFilters;
    surface: TaskSurface;
    statuses: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
    employees?: TaskNamedRef[];
    searchPlaceholder: string;
    emptyTitle: string;
    emptyDescription: string;
}>();

/* --------------------------------------------------------------- the local board */

/**
 * The columns this screen draws.
 *
 * A copy, not the props: a drop moves the card here first so the board answers the pointer,
 * and the server's answer arrives afterwards. Every fresh payload replaces it outright — the
 * server's order is the real one, including after a refusal, where `back()` re-renders the
 * board exactly as it still is.
 */
const local = ref<BoardColumn[]>(cloneColumns(props.board.columns));

/** The board as it was before the optimistic move, kept until the server answers. */
const restorePoint = ref<BoardColumn[] | null>(null);

watch(
    () => props.board,
    (board) => {
        local.value = cloneColumns(board.columns);
        restorePoint.value = null;

        // A new payload can change what sits above the board — the filter bar gains or loses a
        // chip row — so the region asks again where its top edge ended up.
        void nextTick(measureFill);
    },
);

function undo(): void {
    if (restorePoint.value !== null) {
        local.value = restorePoint.value;
        restorePoint.value = null;
    }
}

const filtersActive = computed(() => taskFiltersActive(props.filters));
const filterBar = ref<InstanceType<typeof TaskFilterBar> | null>(null);

/* --------------------------------------------------------------------- the moves */

/** Which card is in flight, so its card can say so and a second drop cannot race it. */
const busyId = ref<number | null>(null);

function movesOf(status: string) {
    return movesFor(status, props.transitions, local.value);
}

/**
 * Move a card, from a drag or from the menu — one path for both.
 *
 * `index` is where it lands in the target column, counted in that column **as it is now**;
 * `moveCard()` takes the card out before it works out which card it landed under, which is
 * what keeps `after_id` right when a card moves inside its own column.
 */
function move(cardId: number, from: string, to: string, index: number): void {
    if (busyId.value !== null) {
        return;
    }

    const source = local.value.find((column) => column.key === from);
    const card = source?.tasks.find((task) => task.id === cardId);

    if (source === undefined || card === undefined) {
        return;
    }

    // Counting the dragged card itself when it is already in the target list puts the
    // insertion one place too low; it is about to be removed.
    let at = index;

    if (from === to) {
        const current = source.tasks.findIndex((task) => task.id === cardId);

        if (current !== -1 && at > current) {
            at -= 1;
        }

        if (current === at) {
            return;
        }
    }

    const moved = moveCard(local.value, cardId, from, to, at);

    if (moved === null) {
        return;
    }

    const before = local.value;

    restorePoint.value = before;
    local.value = moved.columns;

    if (from === to) {
        reorder(cardId, moved.afterId);

        return;
    }

    void changeStatus(card, to, moved.afterId);
}

/** A move inside one column. `after_id` is the card it landed under; absent is the top. */
function reorder(cardId: number, afterId: number | null): void {
    busyId.value = cardId;

    let accepted = false;

    mutateTask(
        'post',
        taskRoutes(props.surface, cardId).reorder,
        afterId === null ? {} : { after_id: afterId },
        {
            onAccepted: () => {
                accepted = true;
            },
            onSettled: () => {
                if (accepted) {
                    restorePoint.value = null;
                } else {
                    undo();
                }

                restoreFocus();
            },
            onFinish: () => {
                busyId.value = null;
            },
        },
    );
}

/* ------------------------------------------------- a move between columns */

/**
 * The card the headless `TaskStatusActions` is bound to.
 *
 * One instance, re-bound per move, rather than one under every card: the dialogs are modal, so
 * only one can ever be open, and forty mounted copies of them is forty copies of a focus trap.
 */
const moveTarget = ref<BoardCard | null>(null);
const statusActions = ref<InstanceType<typeof TaskStatusActions> | null>(null);

async function changeStatus(card: BoardCard, to: string, afterId: number | null): Promise<void> {
    moveTarget.value = card;

    // The component has to exist before it can be asked for anything.
    await nextTick();

    const answer = statusActions.value?.requestMove(to, afterId) ?? 'unavailable';

    if (answer === 'unavailable') {
        /*
         * The role half already said no, so nothing was sent. This is a client-side refusal
         * and therefore a `toast()`, not a flash — the server was never asked, so there is no
         * server sentence to say, and §5.19's "once" rule is kept by there only being one.
         */
        undo();
        toast.error(`${card.status_label} → ${labelOf(to)} is not a move your role can make.`);

        return;
    }

    if (answer === 'committed') {
        busyId.value = card.id;
    }
}

function labelOf(key: string): string {
    return local.value.find((column) => column.key === key)?.label ?? key;
}

/** The server answered a status write. `false` is a refusal, and the card goes back. */
function onOutcome(accepted: boolean): void {
    busyId.value = null;

    if (accepted) {
        restorePoint.value = null;
    } else {
        undo();
    }

    restoreFocus();
}

function onDismissed(): void {
    undo();
    restoreFocus();
}

/* ----------------------------------------------------------- giving focus back */

/**
 * The card whose menu started this move, so focus can go back to it.
 *
 * It is an **id**, not an element. The dialogs live on the Board rather than in the card — one
 * modal can be open at a time, and forty mounted copies of a focus trap is forty too many — so
 * reka has no reference to the card to restore to, and the optimistic move has re-rendered the
 * card into another column by the time the dialog closes anyway. Looking the card up again by
 * id is the only thing that survives both.
 */
const returnTo = ref<number | null>(null);

function restoreFocus(): void {
    const id = returnTo.value;
    returnTo.value = null;

    if (id === null) {
        return;
    }

    void nextTick(() => {
        // After reka has finished its own restore, or it puts focus back on `body` over ours.
        setTimeout(() => {
            strip.value?.querySelector<HTMLElement>(`[data-task-id="${id}"] [data-card-menu]`)?.focus();
        }, 0);
    });
}

/* --------------------------------------------------------------------- dragging */

const drag = ref<{ id: number; from: string } | null>(null);
const over = ref<{ column: string; index: number } | null>(null);

/** Is this column one this role could move the dragged card into? Its own column always is. */
function acceptsDrag(columnKey: string): boolean {
    const held = drag.value;

    if (held === null) {
        return false;
    }

    return held.from === columnKey || (props.transitions[held.from] ?? []).includes(columnKey);
}

function onDragStart(card: BoardCard, columnKey: string, event: DragEvent): void {
    drag.value = { id: card.id, from: columnKey };

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        // Some browsers refuse to start a drag with nothing on the transfer at all.
        event.dataTransfer.setData('text/plain', String(card.id));
    }
}

function onDragEnd(): void {
    drag.value = null;
    over.value = null;
}

/** Where in the list the pointer is, by the midpoint of each card it has passed. */
function indexAt(list: HTMLElement, clientY: number): number {
    let index = 0;

    for (const element of Array.from(list.querySelectorAll<HTMLElement>('[data-board-card]'))) {
        const box = element.getBoundingClientRect();

        if (clientY > box.top + box.height / 2) {
            index += 1;
        }
    }

    return index;
}

function onDragOver(columnKey: string, event: DragEvent): void {
    if (!acceptsDrag(columnKey)) {
        return;
    }

    // Without this the browser's default is "no drop here" and `drop` never fires.
    event.preventDefault();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }

    const list = (event.currentTarget as HTMLElement).querySelector<HTMLElement>('[data-board-list]');

    over.value = { column: columnKey, index: list === null ? 0 : indexAt(list, event.clientY) };
}

/** Leaving for a child of the same column is not leaving the column. */
function onDragLeave(columnKey: string, event: DragEvent): void {
    const to = event.relatedTarget;

    if (to instanceof Node && (event.currentTarget as HTMLElement).contains(to)) {
        return;
    }

    if (over.value?.column === columnKey) {
        over.value = null;
    }
}

function onDrop(columnKey: string, event: DragEvent): void {
    event.preventDefault();

    const held = drag.value;
    const allowed = acceptsDrag(columnKey);
    const at = over.value;

    drag.value = null;
    over.value = null;

    if (held === null || !allowed) {
        return;
    }

    move(held.id, held.from, columnKey, at?.column === columnKey ? at.index : 0);
}

/* ------------------------------------------------------- the keyboard's own path */

/**
 * The menu's moves, down the same two code paths the drag uses.
 *
 * `returnTo` is set here and not in the drag handlers: this move started from a keyboard on a
 * known control, so focus has somewhere to go back to. A mouse drag did not, and pulling focus
 * across the board after one would scroll it out from under the pointer.
 */
function moveTo(card: BoardCard, from: string, to: string): void {
    const column = local.value.find((item) => item.key === to);

    returnTo.value = card.id;
    move(card.id, from, to, column?.tasks.length ?? 0);
}

function nudge(card: BoardCard, columnKey: string, direction: 'up' | 'down'): void {
    const column = local.value.find((item) => item.key === columnKey);

    if (column === undefined) {
        return;
    }

    const where = neighbours(column, card.id);

    returnTo.value = card.id;
    move(card.id, columnKey, columnKey, direction === 'up' ? where.upIndex : where.downIndex);
}

/* ------------------------------------------------------------- getting around it */

const root = ref<HTMLElement | null>(null);
const strip = ref<HTMLElement | null>(null);

/**
 * Eight columns do not fit on a phone, so the counts come to the reader instead: the chips
 * wrap, every column is named and counted without scrolling, and one press both scrolls its
 * column into view and moves focus to its heading.
 */
function jumpTo(key: string): void {
    const column = strip.value?.querySelector<HTMLElement>(`[data-column="${key}"]`);

    column?.scrollIntoView({ block: 'nearest', inline: 'start', behavior: 'smooth' });
    column?.querySelector<HTMLElement>('[data-column-heading]')?.focus();
}

/* ----------------------------------------------------- the region and its height */

/**
 * The height the strip is given, in pixels, or `null` for "grow as you like".
 *
 * There is no number in this file for how tall the board is or how much sits above or below it.
 * It is all **measured**: the strip's own top edge, the page gutter the layout puts under
 * `<main>`, and whatever of the board sits under the strip (the gap and the ordering note).
 * What is left between them is the strip. So the height follows the top bar, the page title,
 * the view switcher, and a filter bar that wraps to two rows at 768 and to five at 375 — and it
 * keeps following them when any of those change.
 *
 * None of those measurements depend on the strip's own height, so this cannot chase itself:
 * everything above it is in normal flow before it, and the note below it is a fixed line.
 */
const fill = ref<number | null>(null);

/** The `md` breakpoint, read from the browser rather than guessed at from `innerWidth`. */
const FILL_FROM = '(min-width: 48rem)';

/**
 * The shortest strip that is still a board: a lane heading and enough of the first card to read
 * and to grab. 10rem on Tailwind's own scale.
 *
 * It is deliberately low. The floor exists for the degenerate case — a window a couple of
 * hundred pixels tall — and every pixel it is raised is another window size where the board
 * goes back to being twice as tall as the screen with its scrollbar under the fold, which is
 * the complaint. A 1366×768 laptop leaves about 220 px here, and that has to be a board.
 */
const FILL_FLOOR = 160;

let fillQuery: MediaQueryList | null = null;
let observer: ResizeObserver | null = null;

function measureFill(): void {
    const el = root.value;
    const scroller = strip.value;

    // No strip means an empty board, which is its own `EmptyState` and wants no region at all.
    if (el === null || scroller === null || fillQuery === null || !fillQuery.matches) {
        fill.value = null;

        return;
    }

    const rootBox = el.getBoundingClientRect();
    const stripBox = scroller.getBoundingClientRect();
    const main = el.closest('main');
    const gutter = main === null ? 0 : Number.parseFloat(window.getComputedStyle(main).paddingBottom) || 0;

    // Whatever the board still draws under the strip: the flex gap and the ordering note.
    const below = rootBox.bottom - stripBox.bottom;

    // Document-relative, so the answer is the same whether or not the page happens to be
    // scrolled when it is taken.
    const top = stripBox.top + window.scrollY;
    const available = Math.floor(document.documentElement.clientHeight - top - below - gutter);

    fill.value = available >= FILL_FLOOR ? available : null;
}

onMounted(() => {
    fillQuery = window.matchMedia(FILL_FROM);
    fillQuery.addEventListener('change', measureFill);
    window.addEventListener('resize', measureFill);

    // The one way a press can end that never reaches this element: the window itself goes away.
    window.addEventListener('blur', releasePan);

    /*
     * `<main>` is what changes size when anything above the board does — the filter bar taking
     * a second row, the title wrapping, the sidebar rail collapsing. Watching it is how the
     * region notices that its top moved. Watching the board itself would not: its height is
     * fixed, so it would never report the change.
     */
    const main = root.value?.closest('main') ?? null;

    if (main !== null && typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(() => measureFill());
        observer.observe(main);
    }

    requestAnimationFrame(measureFill);
});

onBeforeUnmount(() => {
    fillQuery?.removeEventListener('change', measureFill);
    window.removeEventListener('resize', measureFill);
    window.removeEventListener('blur', releasePan);
    observer?.disconnect();
    observer = null;
    releasePan();
});

/* ----------------------------------------------------------------- drag-to-pan */

/**
 * Grab the board's background and the board moves with the pointer — which is what the client
 * asked for by name, and the fastest way across eight lanes now that the strip is one viewport
 * tall.
 *
 * Three things make it either fine or infuriating, so they are all here rather than left to
 * chance:
 *
 * - **It never starts on something that is already a gesture.** A card owns an HTML5 drag; a
 *   button, a link, a menu item and a field own their click. `PAN_IGNORES` is the list, and a
 *   pointer that went down inside any of them is not a pan, so a card drag and a pan can never
 *   fight over the same pointer.
 * - **A grab that did not move is still a click.** The click that follows a pan is swallowed
 *   only once the pointer has travelled past `PAN_SLOP`; below that, the click goes through to
 *   whatever it was on.
 * - **Releasing outside the window ends it.** The pointer is captured, so the release comes
 *   back here even off-screen, and `pointercancel`, `lostpointercapture` and the window's own
 *   `blur` all end the pan too. A board stuck in pan state is worse than no pan at all.
 *
 * Pointer events, not mouse events, so a trackpad is a mouse and a stylus is a pen. **Touch is
 * left alone on purpose:** a finger already pans the strip and scrolls a lane natively, and
 * taking that over means `touch-action: none`, which would take the lane's own scrolling with
 * it.
 */
const PAN_IGNORES = [
    '[data-board-card]',
    'a',
    'button',
    'input',
    'select',
    'textarea',
    'label',
    '[role="button"]',
    '[role="menu"]',
    '[role="menuitem"]',
    '[role="dialog"]',
    '[contenteditable="true"]',
].join(',');

/** How far the pointer travels before a grab stops being a click. */
const PAN_SLOP = 4;

const panning = ref(false);

let panPointer: number | null = null;
let panFrom = { x: 0, y: 0, left: 0, top: 0 };
let panList: HTMLElement | null = null;
let panMoved = false;
let swallowClick = false;

function onPanDown(event: PointerEvent): void {
    // Whatever the last pan decided about its click, this new press settles again.
    swallowClick = false;

    if (event.pointerType === 'touch' || event.button !== 0) {
        return;
    }

    const el = strip.value;
    const target = event.target;

    if (el === null || !(target instanceof Element) || target.closest(PAN_IGNORES) !== null) {
        return;
    }

    /*
     * Kills the text selection and the focus move the press would otherwise start. Nothing
     * focusable is left to lose — every control was excluded above — and `click` still fires,
     * so a grab that did not move still reads as a click.
     */
    event.preventDefault();

    // A pan that began over a lane drags that lane's cards vertically as well as the strip
    // sideways, so one gesture moves the board in the direction it was pushed.
    panList = target.closest<HTMLElement>('[data-board-list]');

    panPointer = event.pointerId;
    panMoved = false;
    panFrom = { x: event.clientX, y: event.clientY, left: el.scrollLeft, top: panList?.scrollTop ?? 0 };
    panning.value = true;

    el.setPointerCapture(event.pointerId);
}

function onPanMove(event: PointerEvent): void {
    const el = strip.value;

    if (!panning.value || el === null || event.pointerId !== panPointer) {
        return;
    }

    const dx = event.clientX - panFrom.x;
    const dy = event.clientY - panFrom.y;

    if (!panMoved && Math.abs(dx) + Math.abs(dy) > PAN_SLOP) {
        panMoved = true;
    }

    el.scrollLeft = panFrom.left - dx;

    if (panList !== null) {
        panList.scrollTop = panFrom.top - dy;
    }
}

function onPanUp(event: PointerEvent): void {
    if (panPointer === null || event.pointerId !== panPointer) {
        return;
    }

    swallowClick = panMoved;
    releasePan();
}

function releasePan(): void {
    const el = strip.value;

    if (el !== null && panPointer !== null && el.hasPointerCapture(panPointer)) {
        el.releasePointerCapture(panPointer);
    }

    panPointer = null;
    panList = null;
    panMoved = false;
    panning.value = false;
}

/** The click that ends a pan that actually moved, caught before it reaches anything. */
function onPanClick(event: MouseEvent): void {
    if (!swallowClick) {
        return;
    }

    swallowClick = false;
    event.preventDefault();
    event.stopPropagation();
}
</script>

<template>
    <div ref="root" class="flex min-w-0 flex-col gap-4">
        <TaskFilterBar
            ref="filterBar"
            :filters="filters"
            :statuses="statuses"
            :priorities="priorities"
            :projects="projects"
            :tags="tags"
            :employees="employees"
            :placeholder="searchPlaceholder"
            :id-prefix="`${surface}-board`"
            class="shrink-0"
        />

        <div class="flex min-w-0 shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <p class="text-xs text-muted-foreground">
                <span class="tabular-nums">{{ board.total }}</span>
                {{ board.total === 1 ? 'task' : 'tasks' }}
                <template v-if="board.overdue_count > 0">
                    ·
                    <span class="font-medium text-destructive">
                        <span class="tabular-nums">{{ board.overdue_count }}</span> overdue
                    </span>
                </template>
            </p>

            <!-- Every column, named and counted, without scrolling to it first. -->
            <nav aria-label="Jump to a column" class="flex min-w-0 flex-wrap items-center gap-1">
                <button
                    v-for="column in local"
                    :key="column.key"
                    type="button"
                    class="rounded-full outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                    @click="jumpTo(column.key)"
                >
                    <StatusBadge
                        :status="column.tone ?? 'todo'"
                        :label="`${column.label} ${column.count}`"
                        size="sm"
                    />
                </button>
            </nav>
        </div>

        <!--
            Nothing at all. A board filtered to nothing is not the same thing as a board with
            no tasks on it, so the two states are the two `EmptyState` variants and the second
            one offers the way out by itself.
        -->
        <div v-if="board.total === 0" class="rounded-xl border bg-card shadow-raised">
            <EmptyState
                :icon="KanbanSquare"
                :variant="filtersActive ? 'filtered' : 'empty'"
                :title="filtersActive ? 'No tasks match these filters' : emptyTitle"
                :description="filtersActive ? 'Clear a filter, or widen the search.' : emptyDescription"
                @clear="filterBar?.clearFilters()"
            />
        </div>

        <!--
            The board scrolls sideways on purpose; the page does not. The negative margin lets
            a column reach the screen edge at 360 instead of stopping at the layout's gutter,
            and the padding puts the gutter back inside the scroller so the first and last
            column are not flush against the glass.

            From `md` up it is also given a **measured** height — everything the viewport has
            left — which is what puts its horizontal scrollbar on screen: the lanes scroll their
            own cards instead of making the page taller. `overscroll-x-contain` keeps a trackpad
            swipe past the last lane from being read as "go back".
        -->
        <div
            v-else
            ref="strip"
            :class="
                cn(
                    '-mx-4 overflow-x-auto overscroll-x-contain px-4 pb-2 md:-mx-6 md:px-6',
                    fill !== null && 'shrink-0 overflow-y-hidden',
                    // Grab over pannable background; a card and every control keep their own.
                    panning ? 'cursor-grabbing select-none' : 'cursor-grab',
                )
            "
            :style="fill === null ? undefined : { height: `${fill}px` }"
            :aria-busy="busyId !== null || undefined"
            @pointerdown="onPanDown"
            @pointermove="onPanMove"
            @pointerup="onPanUp"
            @pointercancel="onPanUp"
            @lostpointercapture="releasePan"
            @click.capture="onPanClick"
        >
            <!--
                `fill` is the one switch: with a measured height the lanes fill it and scroll
                their own cards; without one they are exactly as tall as their contents and the
                page scrolls, which is the 360 answer and the fallback on a very short window.
            -->
            <div
                :class="
                    cn(
                        'flex min-w-max snap-x snap-proximity gap-4',
                        fill === null ? 'items-start' : 'h-full items-stretch',
                    )
                "
            >
                <section
                    v-for="column in local"
                    :key="column.key"
                    :data-column="column.key"
                    :aria-labelledby="`${surface}-col-${column.key}`"
                    :class="
                        cn(
                            'flex w-72 shrink-0 snap-start flex-col rounded-xl bg-muted transition-opacity',
                            fill !== null && 'h-full',
                            // A column this role can never drop into says so while a card is
                            // held, rather than taking the drop and undoing it afterwards.
                            drag !== null && !acceptsDrag(column.key) && 'opacity-40',
                            over?.column === column.key && 'bg-accent',
                        )
                    "
                    @dragover="onDragOver(column.key, $event)"
                    @dragleave="onDragLeave(column.key, $event)"
                    @drop="onDrop(column.key, $event)"
                >
                    <!--
                        Outside the scroller on purpose: a lane you have scrolled into the
                        middle of still says which lane it is, and its count still counts.
                    -->
                    <div class="flex min-w-0 shrink-0 flex-col gap-3 p-3 pb-0">
                        <div class="flex min-w-0 items-center justify-between gap-2">
                            <h2
                                :id="`${surface}-col-${column.key}`"
                                data-column-heading
                                tabindex="-1"
                                class="min-w-0 rounded-full outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                <StatusBadge :status="column.tone ?? 'todo'" :label="column.label" />
                            </h2>
                            <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                                {{ column.count }}
                            </span>
                        </div>

                        <p
                            v-if="drag !== null && !acceptsDrag(column.key)"
                            class="text-xs text-muted-foreground"
                        >
                            Not a move your role can make.
                        </p>
                    </div>

                    <!-- The cards, and the only thing in a lane that scrolls. -->
                    <ul
                        data-board-list
                        :class="
                            cn(
                                'flex min-w-0 flex-col gap-2 p-3',
                                fill !== null && 'min-h-0 flex-1 overflow-y-auto',
                            )
                        "
                    >
                        <template v-for="(card, index) in column.tasks" :key="card.id">
                            <!-- Where the card would land. Neutral, so it is not a second accent. -->
                            <li
                                v-if="over?.column === column.key && over.index === index"
                                class="h-0.5 rounded-full bg-foreground"
                                aria-hidden="true"
                            />
                            <TaskBoardCard
                                :card="card"
                                :surface="surface"
                                :moves="movesOf(card.status)"
                                :can-move-up="index > 0"
                                :can-move-down="index < column.tasks.length - 1"
                                :dragging="drag?.id === card.id"
                                :busy="busyId === card.id"
                                data-board-card
                                :data-task-id="card.id"
                                @drag-start="onDragStart(card, column.key, $event)"
                                @drag-end="onDragEnd"
                                @move-to="(status) => moveTo(card, column.key, status)"
                                @move-up="nudge(card, column.key, 'up')"
                                @move-down="nudge(card, column.key, 'down')"
                            />
                        </template>

                        <li
                            v-if="over?.column === column.key && over.index >= column.tasks.length"
                            class="h-0.5 rounded-full bg-foreground"
                            aria-hidden="true"
                        />

                        <!--
                            An empty column is not a filtered-to-nothing board, so it does not
                            borrow that state's medallion and heading: it is the drop zone,
                            and it says what would go in it.
                        -->
                        <li
                            v-if="column.tasks.length === 0"
                            class="rounded-lg border border-dashed px-3 py-6 text-center text-xs text-muted-foreground"
                        >
                            Nothing in {{ column.label }}
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <!--
            One line, because a board that mixes projects in a lane raises the question. A lane
            is ordered by the manual order and nothing else; the due date only settles cards
            nobody has dragged yet. The List is the view ordered by date — that is the whole
            difference between the two.
        -->
        <p v-if="board.total > 0" class="shrink-0 text-xs text-muted-foreground">
            Drag inside a lane to set its order — it is kept. Drag across lanes to change the
            status. Cards nobody has moved sit in due-date order.
        </p>

        <!--
            The dialogs, and only the dialogs. Cancelling one writes nothing, so the board
            undoes the move it made in anticipation; sending it reports whether the server took
            it, and a refusal undoes the same way.
        -->
        <TaskStatusActions
            v-if="moveTarget"
            ref="statusActions"
            variant="headless"
            :task="asDetail(moveTarget, movesOf(moveTarget.status))"
            :surface="surface"
            :reviewers="[]"
            @outcome="onOutcome"
            @dismissed="onDismissed"
        />
    </div>
</template>
