<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    CalendarDays,
    ChevronDown,
    ChevronsUp,
    ChevronUp,
    GripVertical,
    ListChecks,
    Minus,
    MoreHorizontal,
} from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { tagTone } from '@/Components/Tasks/TaskList.vue';
import type { BoardCard } from '@/Components/Tasks/taskBoard';
import type { TaskSurface, TaskTransition } from '@/Components/Tasks/taskDetail';
import { formatDate, initials, taskRoutes } from '@/Components/Tasks/taskDetail';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

/**
 * One card on the Board. The pattern is `docs/design-refs/07-employee-task-kanban-moru.png` —
 * project line, title, description, people, dates, priority, checklist — borrowed, not cloned.
 *
 * Two things the reference has that this does not, on purpose. Its cards count **comments and
 * attachments**; `TaskResource` sends neither, because they are slice 4, and a zero printed for
 * a number the server never sent is a lie the card would tell on every row. And the reference
 * paints priority as a colour chip: this app has one accent and eight status hues and no ninth
 * (DESIGN.md §5.3), so priority is an arrow whose direction is the rank and a word beside it.
 *
 * **Everything a mouse can do here, the ⋯ menu can do too.** A drag moves a card between
 * columns and reorders it inside one; the menu holds the same two moves as *Move to* and
 * *Move up* / *Move down*. That is the keyboard path, and it is on the card rather than
 * somewhere else on the page because a board of forty cards needs the alternative where the
 * card is.
 */

const props = defineProps<{
    card: BoardCard;
    surface: TaskSurface;
    /** The moves this role may make from this card's column. Empty means the card cannot move. */
    moves: TaskTransition[];
    canMoveUp: boolean;
    canMoveDown: boolean;
    /** True while this card is the one being dragged. */
    dragging: boolean;
    /** True while a write for this card is in flight. */
    busy: boolean;
}>();

const emit = defineEmits<{
    'move-to': [status: string];
    'move-up': [];
    'move-down': [];
    'drag-start': [event: DragEvent];
    'drag-end': [];
}>();

const href = computed(() => taskRoutes(props.surface, props.card.id).show);

/**
 * What a project is called on this card.
 *
 * The employee surface prints the **domain**, which is what `ProjectResource` sends them and
 * what they know a project by; the Admin surface prints the name, because an admin reads
 * projects by client engagement and the same domain carries several. Neither side works around
 * the other's payload — the employee's simply has no client on it, and that is the resource
 * doing its job.
 */
const projectLabel = computed(() => {
    const project = props.card.project;

    if (!project) {
        return null;
    }

    return props.surface === 'employee' ? (project.domain ?? project.name) : project.name;
});

/** Rank by shape, not by a ninth colour. Urgent also takes the foreground weight. */
const PRIORITY_ICON: Record<string, typeof ChevronUp> = {
    urgent: ChevronsUp,
    high: ChevronUp,
    medium: Minus,
    low: ChevronDown,
};

const priorityIcon = computed(() => PRIORITY_ICON[props.card.priority] ?? Minus);
const priorityLoud = computed(() => props.card.priority === 'urgent' || props.card.priority === 'high');

const others = computed(() => Math.max(props.card.assignees.length - 1, 0));
const canDrag = computed(() => props.moves.length > 0 || props.canMoveUp || props.canMoveDown);
</script>

<template>
    <li
        :draggable="canDrag"
        :aria-busy="busy || undefined"
        :class="
            cn(
                'group flex min-w-0 flex-col gap-2 rounded-lg border bg-card p-3 text-card-foreground shadow-raised',
                canDrag && 'cursor-grab active:cursor-grabbing',
                // The strip behind it is `cursor-grab` because its background pans; a card that
                // cannot be dragged must not borrow that promise.
                !canDrag && 'cursor-default',
                dragging && 'opacity-40',
                busy && 'opacity-60',
            )
        "
        @dragstart="emit('drag-start', $event)"
        @dragend="emit('drag-end')"
    >
        <div class="flex min-w-0 items-start justify-between gap-2">
            <p v-if="projectLabel" class="min-w-0 truncate text-xs font-medium text-muted-foreground">
                {{ projectLabel }}
            </p>
            <span v-else class="text-xs text-muted-foreground">No project</span>

            <div class="flex shrink-0 items-center gap-0.5">
                <!--
                    A grip that says the card is draggable. It is `aria-hidden` because it is
                    not the keyboard's way in — the ⋯ menu beside it is, and a second focus
                    stop that only works with a mouse is a stop that wastes a Tab.
                -->
                <GripVertical
                    v-if="canDrag"
                    class="size-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                    aria-hidden="true"
                />

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <!--
                            `data-card-menu` is how the Board finds this button again after a
                            move: the dialog a move opens is mounted on the Board, not in the
                            card, and the card itself is re-rendered by the optimistic move —
                            so focus cannot be restored to an element reference, only to a
                            card id and this marker.
                        -->
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-xs"
                            data-card-menu
                            :aria-label="`Actions for ${card.title}`"
                        >
                            <MoreHorizontal aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuItem as-child>
                            <Link :href="href">Open task</Link>
                        </DropdownMenuItem>

                        <template v-if="canMoveUp || canMoveDown">
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">
                                Order in this column
                            </DropdownMenuLabel>
                            <DropdownMenuItem :disabled="!canMoveUp" @select="emit('move-up')">
                                <ArrowUp aria-hidden="true" />
                                Move up
                            </DropdownMenuItem>
                            <DropdownMenuItem :disabled="!canMoveDown" @select="emit('move-down')">
                                <ArrowDown aria-hidden="true" />
                                Move down
                            </DropdownMenuItem>
                        </template>

                        <template v-if="moves.length > 0">
                            <DropdownMenuSeparator />
                            <!--
                                The same list the drag offers, because it is the same list:
                                the role half of the rule, from `transitions`. A per-task
                                refusal can still come back, and the board puts the card back
                                when it does.
                            -->
                            <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">
                                Change status
                            </DropdownMenuLabel>
                            <DropdownMenuItem
                                v-for="move in moves"
                                :key="move.value"
                                @select="emit('move-to', move.value)"
                            >
                                <StatusBadge :status="move.tone" :label="move.label" size="sm" />
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <!--
            `draggable="false"` on the link: a native anchor drags its own href, which would
            hijack the card's drag and drop a URL into whatever is under the pointer.
        -->
        <Link
            :href="href"
            draggable="false"
            class="min-w-0 rounded-sm text-sm font-medium break-words outline-none hover:underline focus-visible:ring-3 focus-visible:ring-ring/50"
        >
            {{ card.title }}
        </Link>

        <p v-if="card.description" class="line-clamp-2 min-w-0 text-xs text-muted-foreground">
            {{ card.description }}
        </p>

        <div class="flex min-w-0 flex-wrap items-center gap-2">
            <span v-if="card.primary_assignee" class="flex min-w-0 items-center gap-1.5">
                <Avatar class="size-6">
                    <AvatarFallback class="text-xs">
                        {{ initials(card.primary_assignee.name) }}
                    </AvatarFallback>
                </Avatar>
                <span class="min-w-0 truncate text-xs">{{ card.primary_assignee.name ?? '—' }}</span>
                <span v-if="others > 0" class="shrink-0 text-xs text-muted-foreground">+{{ others }} more</span>
            </span>
            <span v-else class="text-xs text-muted-foreground">Unassigned</span>
        </div>

        <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs">
            <!--
                Late prints its word. Red alone says nothing in greyscale or to a screen
                reader, and `is_overdue` is the server's answer, never recomputed here.
            -->
            <span
                v-if="card.due_date"
                :class="cn('inline-flex items-center gap-1', card.is_overdue && 'font-medium text-destructive')"
            >
                <CalendarDays class="size-3" aria-hidden="true" />
                <span class="tabular-nums">{{ formatDate(card.due_date) }}</span>
                <span v-if="card.is_overdue">· Overdue</span>
            </span>

            <span
                :class="
                    cn(
                        'inline-flex items-center gap-1',
                        priorityLoud ? 'font-medium text-foreground' : 'text-muted-foreground',
                    )
                "
            >
                <component :is="priorityIcon" class="size-3" aria-hidden="true" />
                {{ card.priority_label }}
            </span>

            <span
                v-if="card.subtask_count > 0"
                class="inline-flex items-center gap-1 text-muted-foreground"
                :aria-label="`${card.subtasks_done_count} of ${card.subtask_count} checklist items done`"
            >
                <ListChecks class="size-3" aria-hidden="true" />
                <span class="tabular-nums" aria-hidden="true">
                    {{ card.subtasks_done_count }}/{{ card.subtask_count }}
                </span>
            </span>

            <span v-if="card.is_archived" class="rounded-full border px-2 py-0.5 text-muted-foreground">
                Archived
            </span>
        </div>

        <div v-if="card.tags.length > 0" class="flex min-w-0 flex-wrap items-center gap-1">
            <StatusBadge
                v-for="tag in card.tags"
                :key="tag.id"
                :status="tagTone(tag.colour)"
                :label="tag.name"
                size="sm"
            />
        </div>
    </li>
</template>
