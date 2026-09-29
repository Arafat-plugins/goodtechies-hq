<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { GripVertical, ListChecks } from '@lucide/vue';
import { computed } from 'vue';
import OnLeaveFlag from '@/Components/Leave/OnLeaveFlag.vue';
import DueCountdown from '@/Components/Tasks/DueCountdown.vue';
import TaskPriorityFlag from '@/Components/Tasks/TaskPriorityFlag.vue';
import type { BoardCard } from '@/Components/Tasks/taskBoard';
import type { TaskSurface, TaskTransition } from '@/Components/Tasks/taskDetail';
import { initials, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { personTone } from '@/Components/Messages/people';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * One card on the Board: title, description, then one footer row — assignee avatars, the
 * countdown, the checklist count, and the priority flag on the right. No project line and no
 * tag chips (the drawer, the List and the filters still carry both).
 *
 * The card prints **no comment or attachment count**: `TaskResource` sends neither, and a zero
 * printed for a number the server never sent is a lie the card would tell on every row.
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
 * What a press on the card is NOT for: the grip and any other control inside the card keep their
 * own meaning. The title link is handled separately below.
 */
const OWN_CONTROLS = 'a, button, input, textarea, select, label, [data-card-grip]';

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
</script>

<template>
    <!--
        The card is one tab stop that opens the drawer on Enter or Space (nothing on the card binds
        a key to reordering), named by its title.
    -->
    <li
        :draggable="canDrag"
        :aria-busy="busy || undefined"
        tabindex="0"
        :aria-label="card.title"
        :class="
            cn(
                'group flex min-w-0 flex-col gap-2 rounded-lg border bg-card p-3 text-card-foreground shadow-raised outline-none focus-visible:ring-3 focus-visible:ring-ring',
                canDrag ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                dragging && 'opacity-40',
                busy && 'opacity-60',
            )
        "
        @dragstart="emit('drag-start', $event)"
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
                A grip that says the card is draggable. It is `aria-hidden` because it is not a
                keyboard control, and a focus stop that only works with a mouse wastes a Tab.
                `mt-0.5` centres it on the title's first line.
            -->
            <GripVertical
                v-if="canDrag"
                data-card-grip
                class="mt-0.5 size-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                aria-hidden="true"
            />
        </div>

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
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
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
                <DueCountdown :due-date="card.due_date" :status="card.status" />

                <span
                    v-if="card.subtask_count > 0"
                    class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                    :aria-label="`${card.subtasks_done_count} of ${card.subtask_count} checklist items done`"
                >
                    <ListChecks class="size-3" aria-hidden="true" />
                    <span class="tabular-nums" aria-hidden="true">
                        {{ card.subtasks_done_count }}/{{ card.subtask_count }}
                    </span>
                </span>

                <span v-if="card.is_archived" class="shrink-0 rounded-full border px-2 py-0.5 text-xs text-muted-foreground">
                    Archived
                </span>

                <span class="ml-auto inline-flex shrink-0">
                    <TaskPriorityFlag :priority="card.priority" />
                </span>
            </div>
        </TooltipProvider>
    </li>
</template>
