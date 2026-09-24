<script setup lang="ts">
import { CalendarOff } from '@lucide/vue';
import { computed } from 'vue';
import { formatDay } from '@/Components/Leave/leave';

/**
 * *"Assignee on leave"* — the flag Part D §5 and §9 ask for on a task that is due while
 * somebody working on it is away.
 *
 * ## It is a flag and it is never a reassignment
 *
 * Part D §5 is explicit that the task is flagged, not moved, and Part H forbids inventing the
 * rest. This component draws information for an Admin to act on and offers no action of its
 * own: there is no "reassign" button here, and there must not be one. What to do about Thursday
 * is a decision with a person's workload and a client's deadline in it, and nothing on this
 * screen knows either.
 *
 * ## It names who, and until when
 *
 * A flag that says *somebody* is away is a flag nobody can act on, and one without a date makes
 * an Admin open two screens to find out whether it matters. Both come from the server —
 * `TaskResource`'s `assignees_on_leave`, resolved in SQL by `TaskService::query()` — and the
 * server also decides **whose** leave this reader may be shown: an approver sees every
 * assignee's, everybody else sees only their own (`LeaveRequest::taskFlagScopeFor()`). Nothing
 * is derived here (decisions 2-28, 2-31).
 *
 * ## It is not carried by colour
 *
 * The icon and the words are the flag; the tint is decoration on top of them. DESIGN.md §5.6.
 */

const props = defineProps<{
    /** `TaskResource.assignees_on_leave`. Empty means nobody, or nobody this reader may see. */
    people: { name: string; until: string }[];
    /** `compact` is the one-line form a board card or a table cell has room for. */
    variant?: 'default' | 'compact';
}>();

const sentence = computed(() => {
    const people = props.people;

    if (people.length === 0) {
        return '';
    }

    if (people.length === 1) {
        return `${people[0].name} is on leave until ${formatDay(people[0].until)}`;
    }

    if (props.variant === 'compact') {
        return `${people.length} assignees on leave`;
    }

    return `${people.map((person) => person.name).join(', ')} are on leave when this is due`;
});
</script>

<template>
    <p
        v-if="people.length"
        class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-status-waiting-border bg-status-waiting-bg px-2 py-0.5 text-xs text-status-waiting-fg"
    >
        <CalendarOff class="size-3.5 shrink-0" aria-hidden="true" />
        <span class="truncate">{{ sentence }}</span>
    </p>
</template>
