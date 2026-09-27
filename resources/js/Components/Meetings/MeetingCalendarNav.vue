<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import type { MeetingView, MeetingWindow } from '@/Components/Meetings/meetings';
import { meetingsHref } from '@/Components/Meetings/meetings';
import { buttonVariants } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Previous · the month or week · next · *This month*.
 *
 * **All four are links**, not buttons, for the reason the Tasks view switcher is links: the
 * window is in the URL, so a month is bookmarkable, the back button steps back through the
 * months somebody actually looked at, and "the week of the 21st" can be pasted into a message
 * (DESIGN.md §5.10).
 *
 * The heading is an `<h2>` and carries the window in words, so a screen reader arriving at the
 * grid is told which month it is in before it meets the first cell. The arrows are icon-only and
 * therefore named — *Previous month* / *Next month*, with the month itself in the name so two
 * calendars on one page could never share a name.
 *
 * `carry` is whatever else is in the query string — the scope filter — so paging a month does
 * not quietly drop it.
 */

const props = defineProps<{
    view: Extract<MeetingView, 'month' | 'week'>;
    window: MeetingWindow;
    carry: Record<string, string>;
}>();

const unit = computed(() => (props.view === 'month' ? 'month' : 'week'));

function hrefFor(key: string): string {
    return meetingsHref({ ...props.carry, view: props.view, [props.view]: key });
}

const isCurrent = computed(() => props.window.key === props.window.current);
</script>

<template>
    <div class="flex min-w-0 flex-wrap items-center gap-2">
        <Link
            :href="hrefFor(window.previous)"
            :aria-label="`Previous ${unit}`"
            :class="cn(buttonVariants({ variant: 'outline', size: 'icon-sm' }))"
        >
            <ChevronLeft aria-hidden="true" />
        </Link>

        <h2 class="min-w-0 text-sm font-medium">{{ window.label }}</h2>

        <Link
            :href="hrefFor(window.next)"
            :aria-label="`Next ${unit}`"
            :class="cn(buttonVariants({ variant: 'outline', size: 'icon-sm' }))"
        >
            <ChevronRight aria-hidden="true" />
        </Link>

        <Link
            v-if="!isCurrent"
            :href="hrefFor(window.current)"
            :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }))"
        >
            {{ view === 'month' ? 'This month' : 'This week' }}
        </Link>
    </div>
</template>
