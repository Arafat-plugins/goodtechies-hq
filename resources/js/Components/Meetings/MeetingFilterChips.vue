<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { MeetingFilters, MeetingView } from '@/Components/Meetings/meetings';
import { meetingsHref } from '@/Components/Meetings/meetings';
import { cn } from '@/lib/utils';

/**
 * Two filters, and deliberately not a third.
 *
 * **Mine / Everyone I can see** — offered only when it would change something. For anybody but
 * an Admin, `Meeting::visibleTo()` already returns exactly the meetings they organise or are in,
 * so the two answers are the same set and the control would be a switch wired to nothing. The
 * server decides that by asking the data ("is there a meeting you can see that is not yours"),
 * not by naming a role, and sends `scopeOffered`.
 *
 * **Upcoming / Past** — the List's, and only the List's. A month is already a window.
 *
 * Nothing else. A status chip would duplicate the calendar, a project chip would need the
 * project list every viewer may see and would leak the shape of it, and an organiser chip is a
 * directory. Part H: what is not asked for is not built.
 *
 * Each option is a **link**, so the filter is in the URL, a filtered view can be pasted to
 * somebody, and the back button undoes a filter. Selection is `aria-current` plus a filled pill,
 * never the pill alone.
 */

const props = defineProps<{
    view: MeetingView;
    filters: MeetingFilters;
    scopeOffered: boolean;
    /** The whole query string, so a filter change keeps the month somebody is on. */
    query: Record<string, string>;
}>();

function hrefWith(patch: Record<string, string | undefined>): string {
    const next: Record<string, string | undefined> = { ...props.query, ...patch };

    return meetingsHref(next);
}

const SCOPES: { value: 'mine' | 'all'; label: string }[] = [
    { value: 'mine', label: 'Mine' },
    { value: 'all', label: 'Everyone I can see' },
];

const WHENS: { value: 'all' | 'upcoming' | 'past'; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'past', label: 'Past' },
];

const pill =
    'inline-flex h-8 items-center rounded-md border px-3 text-sm whitespace-nowrap transition-colors ' +
    'outline-none focus-visible:ring-3 focus-visible:ring-ring';
</script>

<template>
    <div v-if="scopeOffered || view === 'list'" class="flex min-w-0 flex-wrap items-center gap-x-6 gap-y-2">
        <div v-if="scopeOffered" class="flex min-w-0 flex-wrap items-center gap-2">
            <span id="meetings-scope-label" class="text-xs font-medium text-muted-foreground">Calendar</span>
            <div class="flex flex-wrap items-center gap-1" role="group" aria-labelledby="meetings-scope-label">
                <Link
                    v-for="option in SCOPES"
                    :key="option.value"
                    :href="hrefWith({ scope: option.value === 'mine' ? undefined : option.value })"
                    :aria-current="filters.scope === option.value ? 'true' : undefined"
                    :class="
                        cn(
                            pill,
                            filters.scope === option.value
                                ? 'border-transparent bg-secondary font-medium text-secondary-foreground'
                                : 'bg-background text-muted-foreground hover:text-foreground',
                        )
                    "
                >
                    {{ option.label }}
                </Link>
            </div>
        </div>

        <div v-if="view === 'list'" class="flex min-w-0 flex-wrap items-center gap-2">
            <span id="meetings-when-label" class="text-xs font-medium text-muted-foreground">Showing</span>
            <div class="flex flex-wrap items-center gap-1" role="group" aria-labelledby="meetings-when-label">
                <Link
                    v-for="option in WHENS"
                    :key="option.value"
                    :href="hrefWith({ when: option.value === 'all' ? undefined : option.value })"
                    :aria-current="filters.when === option.value ? 'true' : undefined"
                    :class="
                        cn(
                            pill,
                            filters.when === option.value
                                ? 'border-transparent bg-secondary font-medium text-secondary-foreground'
                                : 'bg-background text-muted-foreground hover:text-foreground',
                        )
                    "
                >
                    {{ option.label }}
                </Link>
            </div>
        </div>
    </div>
</template>
