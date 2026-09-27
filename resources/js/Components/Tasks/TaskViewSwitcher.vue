<script lang="ts">
export type TaskView = 'list' | 'board' | 'calendar' | 'gantt';
</script>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarDays, Columns3, GanttChartSquare, List } from '@lucide/vue';
import { computed } from 'vue';
import type { TaskSurface } from '@/Components/Tasks/taskDetail';
import { queryOf } from '@/lib/tableState';
import { cn } from '@/lib/utils';

/**
 * List · Board · Calendar · Gantt, on all four Tasks screens of both surfaces.
 *
 * Each view is a route of its own, so these are **links**, not tabs: the address bar is the
 * view, a bookmark keeps it, and the browser's own back button does what it looks like it
 * does. `aria-current="page"` is what says which one you are on — the selected pill is the
 * visual half of that, never the whole of it (DESIGN.md §5.6).
 *
 * The filters travel. `TaskService::filters()` reads the same query parameters on all three
 * routes and every controller sends them back, so carrying the string across is all a switch
 * needs to keep a filtered view filtered.
 */

const props = defineProps<{ surface: TaskSurface; current: TaskView }>();

/**
 * Read from Inertia's own `page.url` rather than `window.location`, so the hrefs are recomputed
 * after a filter chip navigates — a switcher still pointing at the previous filter set would
 * quietly undo the filter somebody just set.
 */
const page = usePage();

const query = computed(() => queryOf(page.url));

const VIEWS: { key: TaskView; label: string; icon: typeof List }[] = [
    { key: 'list', label: 'List', icon: List },
    { key: 'board', label: 'Board', icon: Columns3 },
    { key: 'calendar', label: 'Calendar', icon: CalendarDays },
    { key: 'gantt', label: 'Gantt', icon: GanttChartSquare },
];

/** The two views that open a WINDOW on the query rather than reading all of it. */
const WINDOWED: TaskView[] = ['calendar', 'gantt'];

function hrefFor(view: TaskView): string {
    const next = { ...query.value };

    // Overlay state, not filter state: `?detail=` is which record a drawer is showing and
    // `?new=1` is a one-shot the List consumes on arrival. Neither means anything on the
    // route it would be carried to.
    delete next.detail;
    delete next.new;
    // A one-shot the List consumes on arrival, saying why somebody narrow is looking at the
    // List instead of the Gantt. Meaningless on the way back out.
    delete next.from;

    /*
     * The window belongs to the two views that have one, not to the query.
     *
     * `date_from` / `date_to` ARE filters as far as `TaskService` is concerned — which is
     * exactly why they cannot travel everywhere. They are written by the Calendar's month
     * arrows and by the Gantt's window arrows, so carrying them to the List would silently
     * clip it to whichever stretch the last grid happened to be showing, and nothing on that
     * screen would say why half the tasks were missing. Between the Calendar and the Gantt
     * they DO travel: both mean the same thing by them, and a reader switching from one to
     * the other is asking about the same stretch of time.
     */
    if (!WINDOWED.includes(view)) {
        delete next.date_from;
        delete next.date_to;
    }

    // The ruling is the Gantt's alone. `TaskService::filters()` ignores it, but a `?zoom=week`
    // trailing a List view is a parameter that means nothing on the screen it is sitting on.
    if (view !== 'gantt') {
        delete next.zoom;
    }

    const base = `/${props.surface}/tasks${view === 'list' ? '' : `/${view}`}`;
    const search = new URLSearchParams(next).toString();

    return search === '' ? base : `${base}?${search}`;
}
</script>

<template>
    <!--
        A neutral segmented control: the track is `--muted` (flat) and the selected segment is
        a `--card` pill one elevation above it (DESIGN.md §1.5). No tint — the shell already
        spends this screen's one brand colour on the active nav rail (§5.3).
    -->
    <!--
        `flex-wrap` because there are four of these now.
        Three fitted a 320 px content box; four do not, and an `inline-flex` that does not wrap
        simply spills — measured at 320 px it pushed the whole page into a horizontal scroll,
        and at 375 px the last tab was clipped away, which is a view nobody on a phone can
        reach. Wrapping to a second row costs one line of height and keeps every view a tab
        stop at every width (the accessibility floor: no surface overflows at 360 / 375 / 768 /
        1280). A scroller inside the track would have hidden the same tab behind a gesture.
    -->
    <nav aria-label="Task views" class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-md bg-muted p-1">
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
