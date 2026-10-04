<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/Components/ui/button';

/**
 * Polish 007: "‹ Sunday, 4 October 2026 ›" at the START of a page's first row — the date (with
 * its month) between the arrows, instead of two lonely arrows at the far end of an empty row.
 * Today appears only when you have stepped away from it.
 */
defineProps<{
    label: string;
    previousHref: string;
    nextHref: string;
    /** Shown as a "Today" button when set — pass it only when the page is not on today. */
    todayHref?: string | null;
    unit?: string;
}>();
</script>

<template>
    <div class="flex min-w-0 flex-wrap items-center gap-2">
        <Button as-child variant="outline" size="icon">
            <Link :href="previousHref" preserve-scroll>
                <ChevronLeft class="size-4" aria-hidden="true" />
                <span class="sr-only">Previous {{ unit ?? 'day' }}</span>
            </Link>
        </Button>
        <p class="min-w-0 px-1 text-sm font-medium" aria-live="polite">{{ label }}</p>
        <Button as-child variant="outline" size="icon">
            <Link :href="nextHref" preserve-scroll>
                <ChevronRight class="size-4" aria-hidden="true" />
                <span class="sr-only">Next {{ unit ?? 'day' }}</span>
            </Link>
        </Button>
        <Button v-if="todayHref" as-child variant="outline" size="sm">
            <Link :href="todayHref">Today</Link>
        </Button>
        <slot />
    </div>
</template>
