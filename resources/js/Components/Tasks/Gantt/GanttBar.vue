<script setup lang="ts">
import { computed } from 'vue';
import { statusToneClass } from '@/Components/StatusBadge.vue';
import type { GanttGeometry, GanttGrab, GanttTask, GanttWindow } from '@/Components/Tasks/Gantt/gantt';
import {
    ganttBarBox,
    ganttGrabWords,
    ganttSentence,
    GANTT_BIG_STEP,
    GANTT_ROW_HEIGHT,
    GANTT_STEP,
} from '@/Components/Tasks/Gantt/gantt';
import { cn } from '@/lib/utils';

/**
 * One task on the timeline: a bar, a milestone diamond, or an open-ended start mark.
 *
 * **It is a button, and every one of them is a tab stop.** A Gantt whose only affordance is a
 * pointer drag is a Gantt that excludes keyboard users and most assistive tech outright, so
 * the keyboard path is not a fallback here — it is the same path, with the same staging, the
 * same preview and the same commit. `Tab` reaches the bar; `e` chooses what the arrows have
 * hold of; the arrows move it; `Enter` saves; `Escape` puts it back. `GANTT_KEY_HELP` is the
 * sentence that says so, and it is wired to every bar through `aria-describedby`.
 *
 * **Nothing here computes a permission.** `editable` is `can_plan` (the role half —
 * `TaskService::mayPlan()`, the same answer that makes the date fields `prohibited` in
 * `UpdateTaskRequest`) AND this task's own `permissions.can_update` (the record half, which
 * `TaskPolicy` resolved). A bar that may not be moved is drawn without handles and says so in
 * its name, rather than offering grab points that exist to be refused.
 *
 * **The status is in the sentence, not only in the fill.** DESIGN.md §5.6, measured in §1.4:
 * two of the eight statuses are ΔE 0.16 apart under deuteranopia, so a coloured rectangle says
 * nothing. A wide enough bar prints the word; the accessible name always carries it.
 */

const props = defineProps<{
    task: GanttTask;
    /** The geometry to DRAW at — the saved one, or a staged change's. */
    geometry: GanttGeometry;
    window: GanttWindow;
    /** `can_plan` and this task's `permissions.can_update`, resolved by the caller. */
    editable: boolean;
    /** True while the arrow keys have hold of this bar, whether or not it has moved yet. */
    armed: boolean;
    /** True while this bar carries an unsaved change — armed AND its dates differ from saved. */
    staged: boolean;
    /** What the arrow keys have hold of, when this bar is the armed one. */
    grab: GanttGrab;
    /** True while its write is in flight. */
    busy: boolean;
    /** The id of the paragraph holding `GANTT_KEY_HELP`. */
    describedBy: string;
}>();

const emit = defineEmits<{
    (event: 'shift', payload: { grab: GanttGrab; days: number }): void;
    (event: 'cycle-grab'): void;
    (event: 'commit'): void;
    (event: 'cancel'): void;
    (event: 'open'): void;
    (event: 'drag-start', payload: { grab: GanttGrab; clientX: number; pointerId: number }): void;
}>();

const box = computed(() => ganttBarBox(props.window, props.geometry));

const dates = computed(() => ({ start: props.geometry.start_date, due: props.geometry.due_date }));

const isMark = computed(() => props.geometry.shape !== 'bar');

/**
 * The name, and the whole of what a bar means when it cannot be seen.
 *
 * A staged change is IN the name as well as in the live region, so a reader who tabs away and
 * back is told the truth rather than the saved dates under an unsaved bar.
 */
const label = computed(() => {
    const sentence = ganttSentence(props.task.title, props.task.status_label ?? 'No status', props.geometry.shape, dates.value);
    const suffixes: string[] = [];

    if (props.geometry.continues_before) {
        suffixes.push('starts before this window');
    }

    if (props.geometry.continues_after) {
        suffixes.push('runs past this window');
    }

    if (props.geometry.depends_on_offscreen > 0) {
        suffixes.push(
            `waits for ${props.geometry.depends_on_offscreen} task${props.geometry.depends_on_offscreen === 1 ? '' : 's'} outside this window`,
        );
    }

    if (props.geometry.blocks_offscreen > 0) {
        suffixes.push(
            `blocks ${props.geometry.blocks_offscreen} task${props.geometry.blocks_offscreen === 1 ? '' : 's'} outside this window`,
        );
    }

    if (props.busy) {
        suffixes.push('saving');
    } else if (props.staged) {
        suffixes.push('not saved yet, press Enter to save');
    } else if (props.armed) {
        // Armed but unchanged. Saying which end the arrows have hold of is the whole point of
        // arming, so it belongs in the name and not only in the live region a reader may have
        // tabbed past.
        suffixes.push(ganttGrabWords(props.grab, props.geometry.shape).replace(/\.$/, '').toLowerCase());
    } else if (!props.editable) {
        suffixes.push('dates are read-only');
    }

    return suffixes.length === 0 ? sentence : `${sentence}. ${suffixes.join('. ')}`;
});

/** The tint. It is never the only thing that says the status — see the class docblock. */
const tone = computed(() => statusToneClass(props.task.status_tone ?? 'neutral'));

/** Room for the title beside the bar only when the bar is wide enough to hold one. */
const showsText = computed(() => props.geometry.shape === 'bar' && box.value.width >= 72);

/** An open-ended tail wide enough to hold the words says them BESIDE itself, not inside. */
const showsOpenLabel = computed(() => props.geometry.shape === 'open_ended');

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        if (props.staged) {
            event.preventDefault();
            emit('cancel');
        }

        return;
    }

    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();

        // Enter on a bar with something staged saves it. Enter on a bar with nothing staged
        // is the button doing what a button does — it opens the task.
        if (props.staged) {
            emit('commit');
        } else {
            emit('open');
        }

        return;
    }

    if (!props.editable || props.busy) {
        return;
    }

    if (event.key === 'e' || event.key === 'E') {
        event.preventDefault();
        emit('cycle-grab');

        return;
    }

    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();

        const step = event.shiftKey ? GANTT_BIG_STEP : GANTT_STEP;

        emit('shift', { grab: props.grab, days: event.key === 'ArrowLeft' ? -step : step });
    }
}

function onPointerDown(event: PointerEvent, grab: GanttGrab): void {
    // Primary button only, and never while a write is in flight — a second drag racing the
    // first is how a bar ends up somewhere neither the user nor the server chose.
    if (!props.editable || props.busy || event.button !== 0) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    emit('drag-start', { grab, clientX: event.clientX, pointerId: event.pointerId });
}
</script>

<template>
    <div
        class="absolute flex items-center"
        :style="{
            left: `${box.left}px`,
            width: `${box.width}px`,
            height: `${GANTT_ROW_HEIGHT}px`,
        }"
    >
        <!--
            The bar itself. A button, so it is a tab stop, it takes Enter and Space, and the
            focus ring is the one every other control in this app paints.
        -->
        <button
            type="button"
            :class="
                cn(
                    'group relative flex h-6 w-full items-center overflow-hidden text-left transition-[opacity,box-shadow]',
                    'outline-none focus-visible:ring-3 focus-visible:ring-ring',
                    isMark ? 'justify-center' : 'rounded-sm px-1.5',
                    !isMark && tone,
                    // A bar that runs off an edge is squared there and carries a chevron — the
                    // same treatment the Calendar gives a span that leaves its window.
                    !isMark && geometry.continues_before && 'rounded-l-none',
                    !isMark && geometry.continues_after && 'rounded-r-none',
                    // Armed is a lighter ring than staged: one says the arrows are pointed at
                    // this bar, the other says the bar is somewhere it is not yet saved.
                    //
                    // These two keep their alpha while the FOCUS ring above went opaque, and the
                    // difference is deliberate: 1.4.11 is about the focus indicator, and armed
                    // only happens while this button is focused, so the opaque
                    // `focus-visible:ring-ring` is what is actually painted then (a
                    // `:focus-visible` rule outranks an unqualified one). What these two add is a
                    // second, weaker mark distinguishing armed from staged — and neither state is
                    // left to a colour: both are in the accessible name (see the header comment)
                    // and staged is also `data-gantt-staged`. Flattening them to one opacity
                    // would collapse a two-level encoding to buy a ratio nothing reads.
                    armed && !staged && 'ring-2 ring-ring/40',
                    staged && 'ring-3 ring-ring/60',
                    busy && 'opacity-60',
                    editable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                )
            "
            :aria-label="label"
            :aria-describedby="describedBy"
            :aria-busy="busy ? 'true' : undefined"
            :aria-keyshortcuts="editable ? 'ArrowLeft ArrowRight Shift+ArrowLeft Shift+ArrowRight E Enter Escape' : undefined"
            :data-gantt-bar="task.id"
            :data-gantt-shape="geometry.shape"
            :data-gantt-staged="staged ? 'true' : undefined"
            @keydown="onKeydown"
            @pointerdown="onPointerDown($event, 'move')"
        >
            <!--
                A milestone is a diamond and an open-ended task is a half-diamond with a tail.
                Neither is a word, so neither is left to carry the meaning: the accessible name
                says "milestone on 9 October" or "starts 15 September, no due date", and the
                table below the chart says the same in a cell.
            -->
            <span
                v-if="geometry.shape === 'milestone'"
                :class="cn('size-3 rotate-45 border-2', tone)"
                aria-hidden="true"
            />
            <!--
                An open-ended task: a solid cap on its start date, then a tail that fades out
                because the task has no end. A hard right edge would be a due date it does not
                have, and a chevron would mean "runs past this window", which is a different
                thing that a bar next to it is already saying.

                The fade is four opacity steps rather than a gradient, because a gradient needs
                a colour and the fill here is a status token class (DESIGN.md §5.1).
            -->
            <span v-else-if="geometry.shape === 'open_ended'" class="flex h-6 w-full items-stretch" aria-hidden="true">
                <span :class="cn('w-1 shrink-0 rounded-l-sm', tone, 'brightness-75')" />
                <span :class="cn('flex-1', tone)" />
                <span :class="cn('flex-1 opacity-60', tone)" />
                <span :class="cn('flex-1 opacity-30', tone)" />
                <span :class="cn('flex-1 opacity-10', tone)" />
            </span>
            <template v-else>
                <span v-if="geometry.continues_before" class="mr-1 shrink-0 text-xs" aria-hidden="true">‹</span>
                <span v-if="showsText" class="min-w-0 flex-1 truncate text-xs font-medium">
                    {{ task.title }} · {{ task.status_label }}
                </span>
                <span v-if="geometry.continues_after" class="ml-auto shrink-0 pl-1 text-xs" aria-hidden="true">›</span>
            </template>
        </button>

        <!--
            "No due date", in words, beside the tail.

            `aria-hidden`, because the button's own accessible name already ends with it — this
            is the sighted half of the same sentence. It sits OUTSIDE the mark's box so the
            tail's length stays a legibility figure rather than growing to fit a caption.
        -->
        <span
            v-if="showsOpenLabel"
            class="pointer-events-none absolute top-1/2 left-full ml-1 -translate-y-1/2 text-xs whitespace-nowrap text-muted-foreground"
            aria-hidden="true"
        >
            no due date
        </span>

        <!--
            The two resize handles, pointer-only by design: the keyboard reaches both ends
            through `e` on the bar itself, which is one focus stop instead of three and reads
            as one control rather than a row of unlabelled slivers. They are absent, not
            disabled, for a reader who may not change the plan — the reason is said once under
            the chart instead of forty times in forty tooltips.
        -->
        <template v-if="editable && !isMark">
            <span
                class="absolute inset-y-0 left-0 w-1.5 cursor-ew-resize rounded-l-sm opacity-0 transition-opacity hover:bg-foreground/20 hover:opacity-100"
                aria-hidden="true"
                @pointerdown="onPointerDown($event, 'start')"
            />
            <span
                class="absolute inset-y-0 right-0 w-1.5 cursor-ew-resize rounded-r-sm opacity-0 transition-opacity hover:bg-foreground/20 hover:opacity-100"
                aria-hidden="true"
                @pointerdown="onPointerDown($event, 'end')"
            />
        </template>
    </div>
</template>
