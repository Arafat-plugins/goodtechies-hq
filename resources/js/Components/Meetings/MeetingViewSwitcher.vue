<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, CalendarRange, List } from '@lucide/vue';
import type { MeetingView } from '@/Components/Meetings/meetings';
import { meetingsHref } from '@/Components/Meetings/meetings';
import { cn } from '@/lib/utils';

/**
 * List · Month · Week.
 *
 * **Links, not tabs**, and the view is in the URL — so a bookmark keeps the month somebody was
 * looking at, the back button walks back through the views they actually opened, and a link to
 * the week of the 21st is a link. Same call, and the same reason, as `TaskViewSwitcher`
 * (DESIGN.md §5.10). `aria-current="page"` says which one you are on; the pill is the visual
 * half of that and never the whole of it (§5.6).
 *
 * ## What travels and what does not
 *
 * `scope` (mine / everyone) is a question about **which meetings**, so it travels across all
 * three. `when` (upcoming / past) is the List's, because a month is already a window and a
 * "past" chip on it would silently empty September. `month` and `week` are each other's windows
 * and neither means anything on the List — carrying them would clip a list to a month with
 * nothing on the screen saying so, which is the bug `TaskViewSwitcher` documents having had.
 */

const props = defineProps<{
    current: MeetingView;
    /** The whole query string, as the page received it. */
    query: Record<string, string>;
}>();

const VIEWS: { key: MeetingView; label: string; icon: typeof List }[] = [
    { key: 'list', label: 'List', icon: List },
    { key: 'month', label: 'Month', icon: CalendarDays },
    { key: 'week', label: 'Week', icon: CalendarRange },
];

function hrefFor(view: MeetingView): string {
    const next: Record<string, string> = {};

    if (props.query.scope) {
        next.scope = props.query.scope;
    }

    if (view === 'list' && props.query.when) {
        next.when = props.query.when;
    }

    if (view === 'month' && props.query.month) {
        next.month = props.query.month;
    }

    if (view === 'week' && props.query.week) {
        next.week = props.query.week;
    }

    return meetingsHref({ ...next, view });
}
</script>

<template>
    <nav aria-label="Meeting views" class="inline-flex max-w-full items-center gap-1 rounded-md bg-muted p-1">
        <Link
            v-for="view in VIEWS"
            :key="view.key"
            :href="hrefFor(view.key)"
            :aria-current="view.key === current ? 'page' : undefined"
            :class="
                cn(
                    'inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm whitespace-nowrap transition-colors',
                    'outline-none focus-visible:ring-3 focus-visible:ring-ring',
                    view.key === current
                        ? 'bg-card font-medium text-foreground shadow-raised'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
        >
            <component :is="view.icon" class="size-4" aria-hidden="true" />
            {{ view.label }}
        </Link>
    </nav>
</template>
