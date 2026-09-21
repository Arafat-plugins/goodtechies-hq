<script setup lang="ts">
import { Skeleton } from '@/Components/ui/skeleton';
</script>

<template>
    <!-- Echoes a detail screen: title block with actions, then main column + side column. -->
    <div class="flex flex-col gap-6" aria-busy="true" aria-live="polite">
        <span class="sr-only">Loading</span>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex min-w-0 flex-col gap-2">
                <Skeleton class="bg-muted-foreground/20 h-7 w-64 max-w-full" />
                <Skeleton class="bg-muted-foreground/20 h-4 w-40 max-w-full" />
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <Skeleton class="bg-muted-foreground/20 h-9 w-24" />
                <Skeleton class="bg-muted-foreground/20 h-9 w-24" />
            </div>
        </div>

        <div class="grid min-w-0 gap-4 md:grid-cols-3">
            <div class="flex flex-col gap-4 md:col-span-2">
                <div class="flex flex-col gap-3 rounded-xl border bg-card p-6 shadow-xs">
                    <Skeleton class="bg-muted-foreground/20 h-4 w-32" />
                    <Skeleton class="bg-muted-foreground/20 h-3 w-full" />
                    <Skeleton class="bg-muted-foreground/20 h-3 w-5/6" />
                    <Skeleton class="bg-muted-foreground/20 h-3 w-2/3" />
                </div>
                <div class="flex flex-col gap-3 rounded-xl border bg-card p-6 shadow-xs">
                    <Skeleton class="bg-muted-foreground/20 h-4 w-40" />
                    <Skeleton class="bg-muted-foreground/20 h-3 w-full" />
                    <Skeleton class="bg-muted-foreground/20 h-3 w-3/4" />
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs">
                    <Skeleton class="bg-muted-foreground/20 h-4 w-24" />
                    <Skeleton class="bg-muted-foreground/20 h-3 w-full" />
                    <Skeleton class="bg-muted-foreground/20 h-3 w-2/3" />
                </div>
                <div class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs">
                    <Skeleton class="bg-muted-foreground/20 h-4 w-20" />
                    <Skeleton class="bg-muted-foreground/20 h-3 w-3/4" />
                </div>
            </div>
        </div>
    </div>
</template>
