<script lang="ts">
import type { Component } from 'vue';

/**
 * One thing a person has to deal with. Every row is a link to the thing itself —
 * an action list is only useful if the row takes you where the work is.
 */
export interface AttentionItem {
    id: string | number;
    /** A lucide icon naming the kind of item (approval, overdue task, leave request…). */
    icon: Component;
    title: string;
    /** The one line that says why it is here: who, or how late. */
    meta?: string;
    href: string;
    /** `urgent` tints the medallion with the cancelled status; everything else is neutral. */
    tone?: 'default' | 'urgent';
}
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Inbox } from '@lucide/vue';
import { Card } from '@/Components/ui/card';
import EmptyState from '@/Components/EmptyState.vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        title: string;
        /** No data source exists yet, so the default is genuinely empty, never a sample. */
        items?: AttentionItem[];
        emptyTitle: string;
        emptyDescription?: string;
        emptyIcon?: Component;
        /**
         * A standing footnote, drawn under either state.
         *
         * A panel fed by four sources of which two are built cannot say what it is missing in
         * its empty state alone: the moment one real item arrives the empty state is gone and
         * the reader is looking at a list that silently claims to be everything. The note is
         * where "approvals arrive in Phase 8" keeps being true whether the list has rows in it
         * or not.
         */
        note?: string;
    }>(),
    { items: () => [], emptyIcon: () => Inbox },
);
</script>

<template>
    <Card class="gap-4 p-6 shadow-xs">
        <div class="flex min-w-0 items-center justify-between gap-2">
            <h2 class="text-sm font-medium">{{ title }}</h2>
            <slot name="action" />
        </div>

        <EmptyState
            v-if="items.length === 0"
            :icon="emptyIcon"
            variant="empty"
            :title="emptyTitle"
            :description="emptyDescription"
        />

        <ul v-else class="flex min-w-0 flex-col">
            <li v-for="item in items" :key="item.id" class="min-w-0 border-b last:border-b-0">
                <Link
                    :href="item.href"
                    class="flex min-w-0 items-center gap-3 rounded-md px-2 py-3 outline-none transition-colors hover:bg-accent/40 focus-visible:ring-3 focus-visible:ring-ring/50"
                >
                    <span
                        :class="
                            cn(
                                'flex size-8 shrink-0 items-center justify-center rounded-full',
                                item.tone === 'urgent'
                                    ? 'bg-status-cancelled-bg text-status-cancelled-fg'
                                    : 'bg-muted text-muted-foreground',
                            )
                        "
                    >
                        <component :is="item.icon" class="size-4" aria-hidden="true" />
                    </span>
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span class="truncate text-sm font-medium">{{ item.title }}</span>
                        <span v-if="item.meta" class="truncate text-xs text-muted-foreground">{{ item.meta }}</span>
                    </span>
                    <ChevronRight class="ml-auto size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                </Link>
            </li>
        </ul>

        <p v-if="note" class="text-xs text-muted-foreground">{{ note }}</p>
    </Card>
</template>
