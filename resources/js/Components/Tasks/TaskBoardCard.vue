<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Clock, CornerDownRight, ListChecks, ListTree, MessageSquare, Paperclip } from '@lucide/vue';
import { computed } from 'vue';
import OnLeaveFlag from '@/Components/Leave/OnLeaveFlag.vue';
import DueCountdown from '@/Components/Tasks/DueCountdown.vue';
import TaskPriorityFlag from '@/Components/Tasks/TaskPriorityFlag.vue';
import RunningTimers from '@/Components/Timer/RunningTimers.vue';
import TaskTimerButton from '@/Components/Timer/TaskTimerButton.vue';
import { formatDuration, spokenDuration } from '@/Components/Timer/timer';
import type { BoardCard } from '@/Components/Tasks/taskBoard';
import type { TaskSurface, TaskTransition } from '@/Components/Tasks/taskDetail';
import { initials, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { personTone } from '@/Components/Messages/people';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * One card on the Board: title, description, then one footer row — assignee avatars, the
 * countdown, the checklist and subtask counters, the comment and attachment counts, and the
 * priority flag on the right. No project line and no tag chips (the drawer, the List and the
 * filters still carry both).
 *
 * The comment and attachment counts (brief 012) are each drawn only above zero, like the two
 * counters before them: a row of zeros is noise on every fresh card.
 * Priority is a flag in a `--priority-*` token with its word printed beside it (DESIGN.md
 * §1.4b), so the colour is never the only carrier.
 *
 * **The card never reads the clock.** `DueCountdown` is the only subscriber to the shared minute
 * ticker, so a tick re-renders the labels and not this component (nothing below reads `now`).
 *
 * **The card has no ⋯ menu** (removed on the client's request, brief 007). A drag moves a card
 * between columns and reorders it inside one; without a mouse, the status is changed from the
 * drawer's status control, and order inside a lane has no keyboard path.
 */

const props = defineProps<{
    card: BoardCard;
    surface: TaskSurface;
    /** The moves this role may make from this card's column. Empty means no drag to another lane. */
    moves: TaskTransition[];
    /** Whether a drag can reorder the card inside its lane (it has a neighbour above / below). */
    canMoveUp: boolean;
    canMoveDown: boolean;
    /** True while this card is the one being dragged. */
    dragging: boolean;
    /** True while a write for this card is in flight. */
    busy: boolean;
}>();

const emit = defineEmits<{
    'drag-start': [event: DragEvent];
    'drag-end': [];
    /** Open this card in the Board's detail drawer. */
    open: [];
}>();

/**
 * What a press on the card is NOT for: any control inside the card keeps its own meaning. The
 * title link is handled separately below. There is no grip (removed, brief 026): the whole card
 * is the drag handle, so a press anywhere else is a click or the start of a drag.
 */
const OWN_CONTROLS = 'a, button, input, textarea, select, label';

/**
 * A plain left-click anywhere on the card opens the drawer — on the title too, whose `href` stays
 * so Ctrl/Cmd/Shift-click and middle-click still open the full page in a new tab.
 *
 * Capture phase, and `preventDefault()` on the title: Inertia's `Link` skips any click that is
 * already `defaultPrevented`, so the visit never starts and the board is not re-rendered. A native
 * drag never ends in a click, and the Board swallows the click that ends a pan before it gets here.
 */
function onClickCapture(event: MouseEvent): void {
    if (event.button !== 0 || !(event.target instanceof Element)) {
        return;
    }

    if (event.target.closest('[data-card-title]') !== null) {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        emit('open');

        return;
    }

    if (event.target.closest(OWN_CONTROLS) === null) {
        emit('open');
    }
}

/** Enter on the title link opens the drawer too; Inertia would otherwise visit on keydown. */
function onKeydownCapture(event: KeyboardEvent): void {
    if (event.key === 'Enter' && event.target instanceof Element && event.target.closest('[data-card-title]') !== null) {
        event.preventDefault();
        emit('open');
    }
}

const href = computed(() => taskRoutes(props.surface, props.card.id).show);

/** At most this many faces; the rest fold into one `+N`. */
const MAX_AVATARS = 3;

/** The primary assignee first, then the others in the payload's order. */
const people = computed(() => {
    const assignees = [...props.card.assignees].sort((a, b) => Number(b.is_primary) - Number(a.is_primary));

    if (assignees.length === 0 && props.card.primary_assignee) {
        return [{ ...props.card.primary_assignee, is_primary: true }];
    }

    return assignees;
});

const shown = computed(() => people.value.slice(0, MAX_AVATARS));
const hidden = computed(() => people.value.slice(MAX_AVATARS));
const hiddenNames = computed(() => hidden.value.map((person) => person.name ?? 'Unnamed assignee').join(', '));
const canDrag = computed(() => props.moves.length > 0 || props.canMoveUp || props.canMoveDown);

/**
 * Flow F3, brief 029: whether this reader gets the timer button. When they do, the button
 * carries the task's tracked time (`▶ 18h 11m`, then the ticking total), so the separate 🕒
 * counter is drawn only on cards with no button — readers who may not time this task.
 */
const showTimer = computed(() => props.card.permissions.can_track_time || props.card.my_timer !== null);

/**
 * Flow F3: a press that began on the timer's buttons is a press, never the start of a drag.
 * A native `dragstart` fires on the draggable `<li>`, not on the button inside it, so the card
 * remembers where the pointer went down and refuses the drag from there.
 */
let pressOnTimer = false;

function onPointerDownCapture(event: PointerEvent): void {
    pressOnTimer = event.target instanceof Element && event.target.closest('[data-card-timer]') !== null;
}

function onDragStart(event: DragEvent): void {
    if (pressOnTimer) {
        event.preventDefault();

        return;
    }

    emit('drag-start', event);
}
</script>

<template>
    <!--
        The card is one tab stop that opens the drawer on Enter or Space (nothing on the card binds
        a key to reordering), named by its title.

        `relative` makes the card the containing block for its `sr-only` labels (the countdown's,
        the priority's, the timer's live region). Without it they are positioned against the
        page, at their static place in a column scrolled far to the right, and the whole
        document scrolled sideways on the Board (found by the F3 browser check, 1279 px at 1280).
    -->
    <li
        :draggable="canDrag"
        :aria-busy="busy || undefined"
        tabindex="0"
        :aria-label="card.title"
        :class="
            cn(
                'group relative flex min-w-0 flex-col gap-2 rounded-lg border bg-card p-3 text-card-foreground shadow-raised outline-none focus-visible:ring-3 focus-visible:ring-ring',
                canDrag ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                dragging && 'opacity-40',
                busy && 'opacity-60',
            )
        "
        @pointerdown.capture="onPointerDownCapture"
        @dragstart="onDragStart"
        @dragend="emit('drag-end')"
        @click.capture="onClickCapture"
        @keydown.capture="onKeydownCapture"
        @keydown.enter.self.prevent="emit('open')"
        @keydown.space.self.prevent="emit('open')"
    >
        <div class="flex min-w-0 items-start gap-2">
            <!--
                `draggable="false"` on the link: a native anchor drags its own href, which would
                hijack the card's drag and drop a URL into whatever is under the pointer.
            -->
            <Link
                :href="href"
                draggable="false"
                data-card-title
                class="min-w-0 flex-1 rounded-sm text-sm font-medium break-words outline-none hover:underline focus-visible:ring-3 focus-visible:ring-ring"
            >
                {{ card.title }}
            </Link>

            <!--
                Brief 026: the priority flag sits in the top-right corner, where the drag grip was.
                `mt-0.5` centres it on the title's first line. The card itself is the drag handle.
            -->
            <TaskPriorityFlag :priority="card.priority" class="mt-0.5" data-card-priority />
        </div>

        <!-- Flow F2: a subtask names its parent — only when the server sent the parent at all. -->
        <p
            v-if="card.parent"
            data-card-parent
            class="flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
        >
            <CornerDownRight class="size-3 shrink-0" aria-hidden="true" />
            <span class="sr-only">Subtask of</span>
            <span class="min-w-0 truncate">{{ card.parent.title }}</span>
        </p>

        <p v-if="card.description" class="line-clamp-2 min-w-0 text-xs text-muted-foreground">
            {{ card.description }}
        </p>

        <!--
            "Assignee on leave" (Part D §5 and §9). Its own line, because it is a sentence and
            the footer under it is a row of marks. `compact` keeps it to one line on a 288px
            lane. It is information: the card offers no reassign control, and must not.
        -->
        <OnLeaveFlag :people="card.assignees_on_leave ?? []" variant="compact" />

        <TooltipProvider>
            <!--
                Brief 026: ONE row that never wraps and never overflows. Avatars and the timer
                button are `shrink-0`. The counters' group gives way FIRST (`shrink-[100000]`, so
                the countdown's share of any shortfall rounds to nothing): whole counters drop out
                of sight (the drawer still carries them). Only once the group is empty does the
                countdown truncate with `…` (`min-w-6`; its tooltip carries the full text).
            -->
            <div class="flex min-w-0 flex-nowrap items-center gap-2" data-card-footer>
                <!-- Faces only: the name is in the tooltip and the accessible name, never beside it. -->
                <div class="flex shrink-0 items-center -space-x-1" data-card-assignees>
                    <Tooltip v-for="person in shown" :key="person.id">
                        <TooltipTrigger as-child>
                            <Avatar
                                role="img"
                                :aria-label="person.name ?? 'Unnamed assignee'"
                                data-card-avatar
                                class="size-6 ring-2 ring-card"
                            >
                                <AvatarFallback :class="cn('text-xs', personTone(person.id).avatar)" aria-hidden="true">
                                    {{ initials(person.name) }}
                                </AvatarFallback>
                            </Avatar>
                        </TooltipTrigger>
                        <TooltipContent>{{ person.name ?? 'Unnamed assignee' }}</TooltipContent>
                    </Tooltip>

                    <Tooltip v-if="hidden.length > 0">
                        <TooltipTrigger as-child>
                            <span
                                role="img"
                                :aria-label="`${hidden.length} more: ${hiddenNames}`"
                                data-card-avatar-more
                                class="relative inline-flex size-6 items-center justify-center rounded-full bg-muted text-xs text-muted-foreground tabular-nums ring-2 ring-card"
                            >
                                <span aria-hidden="true">+{{ hidden.length }}</span>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>{{ hiddenNames }}</TooltipContent>
                    </Tooltip>

                    <Tooltip v-if="shown.length === 0">
                        <TooltipTrigger as-child>
                            <span
                                role="img"
                                aria-label="Unassigned"
                                data-card-unassigned
                                class="inline-flex size-6 rounded-full border border-dashed border-muted-foreground"
                            />
                        </TooltipTrigger>
                        <TooltipContent>Unassigned</TooltipContent>
                    </Tooltip>
                </div>

                <!-- Overdue prints its word ("… overdue"); red is the emphasis, not the message. -->
                <DueCountdown :due-date="card.due_date" :status="card.status" truncate />

                <span class="flex h-4 min-w-0 shrink-[100000] flex-wrap items-center gap-x-2 overflow-hidden" data-card-counts>
                <!--
                    Follow-up to brief 026: the counters give way BEFORE the countdown, and
                    whole. The row is one `h-4` line that wraps into a clipped second line,
                    so the LAST items drop first: tracked time (only on cards without the timer
                    button, brief 029), then comments (attachments with them), then subtasks
                    and the checklist. `leading-3.5` keeps the
                    Archived chip inside that 16 px line.

                    The zero-width, full-height spacer holds line one, so even the first counter can wrap
                    away whole when it no longer fits (a flex line always keeps its first item,
                    which would otherwise be cut mid-glyph). `-mr-2` cancels the gap after it.
                -->
                <span class="-mr-2 h-4 w-0 shrink-0" aria-hidden="true" data-card-counts-spacer />
                <span v-if="card.is_archived" class="shrink-0 rounded-full border px-2 text-xs leading-3.5 text-muted-foreground">
                    Archived
                </span>

                <span
                    v-if="card.checklist_count > 0"
                    class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                    :aria-label="`${card.checklist_done_count} of ${card.checklist_count} checklist items done`"
                >
                    <ListChecks class="size-3" aria-hidden="true" />
                    <span class="tabular-nums" aria-hidden="true">
                        {{ card.checklist_done_count }}/{{ card.checklist_count }}
                    </span>
                </span>

                <!--
                    Flow F2: the parent's subtask progress, in the checklist counter's own style
                    beside it. A different glyph (a tree, not ticks) so the two numbers are told
                    apart without reading the label.
                -->
                <span
                    v-if="card.subtask_count > 0"
                    data-card-subtasks
                    class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                    :aria-label="`${card.subtask_done_count} of ${card.subtask_count} subtasks completed`"
                >
                    <ListTree class="size-3" aria-hidden="true" />
                    <span class="tabular-nums" aria-hidden="true">
                        {{ card.subtask_done_count }}/{{ card.subtask_count }}
                    </span>
                </span>

                <span
                    v-if="card.attachment_count > 0"
                    role="img"
                    data-card-attachments
                    class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                    :aria-label="`${card.attachment_count} ${card.attachment_count === 1 ? 'attachment' : 'attachments'}`"
                >
                    <Paperclip class="size-3" aria-hidden="true" />
                    <span class="tabular-nums" aria-hidden="true">{{ card.attachment_count }}</span>
                </span>

                <!--
                    Brief 012: the discussion's messages and the task's files. Numbers only; each
                    names itself in its accessible name, since a bubble and a clip are not words.
                -->
                <span
                    v-if="card.comment_count > 0"
                    role="img"
                    data-card-comments
                    class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                    :aria-label="`${card.comment_count} ${card.comment_count === 1 ? 'comment' : 'comments'}`"
                >
                    <MessageSquare class="size-3" aria-hidden="true" />
                    <span class="tabular-nums" aria-hidden="true">{{ card.comment_count }}</span>
                </span>

                <!--
                    Flow F3: the task's total tracked time — every approved entry, remote or an
                    office/Admin breakdown. Drawn from a whole minute up, like the counters beside it
                    (under a minute it would read "0m"). Brief 029: only where there is NO timer
                    button — the button shows this same total inside itself.
                -->
                <span
                    v-if="!showTimer && card.tracked_seconds >= 60"
                    role="img"
                    data-card-tracked
                    class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                    :aria-label="`${spokenDuration(card.tracked_seconds)} tracked`"
                >
                    <Clock class="size-3" aria-hidden="true" />
                    <span class="tabular-nums" aria-hidden="true">{{ formatDuration(card.tracked_seconds) }}</span>
                </span>

                </span>

                <!--
                    Flow F3, brief 029: ONE button — ▶ 18h 11m / ⏸ 18:11:05 / ▶ 18:11:05 — only
                    where the server said this reader may time it. It carries the tracked total.
                -->
                <span class="ml-auto inline-flex shrink-0 items-center">
                    <TaskTimerButton
                        v-if="showTimer"
                        :task-id="card.id"
                        :my-timer="card.my_timer"
                        :tracked-seconds="card.tracked_seconds"
                    />
                </span>
            </div>

            <!--
                Flow F3, watchers only: who else is timing this card, on its own row directly under
                the footer (a 288 px lane has no room for it beside the button). `nowrap` keeps it
                to one line; a crowded row truncates the durations with `…`. The key is absent from
                everybody else's payload, so this never mounts for an employee.
            -->
            <RunningTimers
                v-if="card.running_timers && card.running_timers.length > 0"
                :timers="card.running_timers"
                nowrap
            />
        </TooltipProvider>
    </li>
</template>
