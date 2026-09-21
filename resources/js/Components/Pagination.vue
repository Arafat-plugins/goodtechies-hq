<script lang="ts">
/** The `links` block of a Laravel resource collection. */
export interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

/** The `meta` block of a Laravel resource collection. */
export interface PaginationMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    links: { url: string | null; label: string; page: number | null; active: boolean }[];
    path: string;
    per_page: number;
    to: number | null;
    total: number;
}

/** A paginated resource collection, as every Admin list controller returns it. */
export interface Paginated<T> {
    data: T[];
    links: PaginationLinks;
    meta: PaginationMeta;
}
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { buttonVariants } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

const props = defineProps<{
    links: PaginationLinks;
    meta: PaginationMeta;
}>();

/** One page of results needs no controls at all. */
const visible = computed(() => props.meta.last_page > 1);

/** Laravel puts "« Previous" and "Next »" in `meta.links` too; only the numbered ones belong here. */
const pages = computed(() => props.meta.links.filter((link) => link.page !== null));

const summary = computed(() => {
    const from = props.meta.from ?? 0;
    const to = props.meta.to ?? 0;

    return `Showing ${from}–${to} of ${props.meta.total}`;
});

const stepClass = buttonVariants({ variant: 'outline', size: 'sm' });
const disabledStepClass = cn(stepClass, 'pointer-events-none opacity-50');
const pageClass = buttonVariants({ variant: 'ghost', size: 'sm' });
const activePageClass = cn(buttonVariants({ variant: 'default', size: 'sm' }), 'tabular-nums');
</script>

<template>
    <nav
        v-if="visible"
        class="flex flex-col items-center gap-3 border-t pt-4 sm:flex-row sm:justify-between"
        aria-label="Pagination"
    >
        <p class="text-xs text-muted-foreground tabular-nums">{{ summary }}</p>

        <div class="flex flex-wrap items-center justify-center gap-1">
            <Link v-if="links.prev" :href="links.prev" preserve-scroll :class="stepClass" rel="prev">
                <ChevronLeft aria-hidden="true" />
                Previous
            </Link>
            <span v-else :class="disabledStepClass" aria-disabled="true">
                <ChevronLeft aria-hidden="true" />
                Previous
            </span>

            <template v-for="page in pages" :key="page.label">
                <Link
                    v-if="page.url && !page.active"
                    :href="page.url"
                    preserve-scroll
                    :class="cn(pageClass, 'hidden tabular-nums md:inline-flex')"
                >
                    {{ page.label }}
                </Link>
                <span
                    v-else
                    :class="cn(activePageClass, 'hidden md:inline-flex')"
                    :aria-current="page.active ? 'page' : undefined"
                >
                    {{ page.label }}
                </span>
            </template>

            <Link v-if="links.next" :href="links.next" preserve-scroll :class="stepClass" rel="next">
                Next
                <ChevronRight aria-hidden="true" />
            </Link>
            <span v-else :class="disabledStepClass" aria-disabled="true">
                Next
                <ChevronRight aria-hidden="true" />
            </span>
        </div>
    </nav>
</template>
