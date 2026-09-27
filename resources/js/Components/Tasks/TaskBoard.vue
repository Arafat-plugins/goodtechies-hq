<script setup lang="ts">
import { KanbanSquare } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import LiveIndicator from '@/Components/Realtime/LiveIndicator.vue';
import { BOARD_POLL_MS } from '@/Components/Realtime/live';
import { useLiveProps } from '@/Components/Realtime/reload';
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
 */

const props = defineProps<{
    board: BoardPayload;
    transitions: TransitionMap;
    filters: TaskFilters;
    surface: TaskSurface;
    statuses: TaskOption[];
    /** The bucket chip's options. Optional, so a screen that offers no bucket simply has none. */
    buckets?: TaskOption[];
    priorities: TaskOption[];
    projects: TaskNamedRef[];
    tags: TaskTag[];
    /** Server-resolved (`canManageTags`); passed straight through to the filter bar. */
    canManageTags?: boolean;
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
                live.resume();
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
    live.resume();
}

function onDismissed(): void {
    undo();
    restoreFocus();
    live.resume();
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

    // Whatever a colleague did while this card was in the air lands now — unless the drop left a
    // write in flight, in which case `onOutcome` picks it up instead.
    live.resume();
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

/* ------------------------------------------------------------- somebody else's move */

/**
 * A card moved by somebody else moves here too — POLISH-BACKLOG §A.3, whose note on this line is
 * *"today two people dragging the same board overwrite each other silently"*.
 *
 * ## Why this is a poll on BOTH builds, and not a channel
 *
 * There is no board channel, and there is no honest way to add one. A channel needs an audience a
 * policy can answer for, and a board's audience is *"everyone whose `Task::visibleTo()` plus these
 * filter parameters include this card"* — which is a query per reader, not a room. The nearest
 * real thing is `task.{id}` per visible card: forty subscriptions for one screen, re-authorised on
 * every reconnect, and it still would not deliver the case that matters, because a card moving
 * INTO this board was never on it to be subscribed to. So the board re-asks the server, every
 * twenty seconds, on a socket build and on a polling one alike. That is not the fallback here; it
 * is the design, and it is the third rule of §A.4 answered rather than worked around.
 *
 * Twenty seconds is the answer to *how long may a card sit in the wrong column* — long enough
 * that the board is not a chat, short enough that two people planning a sprint together are
 * looking at the same board.
 *
 * ## It never lands mid-gesture
 *
 * `board` is a prop and `local` is a copy of it, so a fresh payload REPLACES the columns — which
 * is exactly right after somebody else's move and exactly wrong while a card is in the air or a
 * write is unanswered. So the refresh is refused while `drag` holds a card, while `busyId` has a
 * write out, and while `restorePoint` is holding the board it would have to restore to on a
 * refusal. A refusal is not dropped: `useLiveRefresh` remembers it, the indicator says *Update
 * waiting*, and `resume()` delivers it the moment the gesture or the write finishes.
 *
 * ## What is still not solved, and cannot be from here
 *
 * This ends *silent* overwriting, not simultaneous editing. Two people who drop the same card in
 * the same second still race, and the second write wins — `POST …/status` has no version check to
 * lose on. Closing that needs an `If-Unmodified-Since`-shaped precondition on the task, which is
 * a server change with its own refusal to draw and is not this slice.
 */
const live = useLiveProps(['board'], {
    intervalMs: BOARD_POLL_MS,
    canRefresh: () => drag.value === null && busyId.value === null && restorePoint.value === null,
});
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
            :id-prefix="`${surface}-board`"
        />

        <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
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

            <!--
                How this board is keeping itself current. It says `Every 20s` on the client's
                build and `Live` on nothing, because there is no board channel — see `live`
                above — and it says `Update waiting` while a drag is holding a refresh off.
            -->
            <LiveIndicator
                :transport="live.transport.value"
                :interval-ms="BOARD_POLL_MS"
                :pending="live.pending.value"
                subject="cards other people move"
            />

            <!-- Every column, named and counted, without scrolling to it first. -->
            <nav aria-label="Jump to a column" class="flex min-w-0 flex-wrap items-center gap-1">
                <button
                    v-for="column in local"
                    :key="column.key"
                    type="button"
                    class="rounded-full outline-none focus-visible:ring-3 focus-visible:ring-ring"
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
        -->
        <div
            v-else
            ref="strip"
            class="-mx-4 overflow-x-auto px-4 pb-2 md:-mx-6 md:px-6"
            :aria-busy="busyId !== null || undefined"
        >
            <div class="flex min-w-max snap-x snap-proximity items-start gap-4">
                <section
                    v-for="column in local"
                    :key="column.key"
                    :data-column="column.key"
                    :aria-labelledby="`${surface}-col-${column.key}`"
                    :class="
                        cn(
                            'flex w-72 shrink-0 snap-start flex-col gap-3 rounded-xl bg-muted p-3 transition-opacity',
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
                    <div class="flex min-w-0 items-center justify-between gap-2">
                        <h2
                            :id="`${surface}-col-${column.key}`"
                            data-column-heading
                            tabindex="-1"
                            class="min-w-0 rounded-full outline-none focus-visible:ring-3 focus-visible:ring-ring"
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

                    <ul data-board-list class="flex min-w-0 flex-col gap-2">
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
        <p v-if="board.total > 0" class="text-xs text-muted-foreground">
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
