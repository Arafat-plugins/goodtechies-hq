<script setup lang="ts">
import { Hourglass } from '@lucide/vue';
import type { Component } from 'vue';
import { Card } from '@/Components/ui/card';

withDefaults(
    defineProps<{
        title: string;
        phase: number;
        /** One line of help text under the empty state. */
        description?: string;
        icon?: Component;
    }>(),
    { icon: () => Hourglass },
);
</script>

<template>
    <Card class="gap-4 p-6 shadow-xs">
        <h2 class="text-sm font-medium">{{ title }}</h2>
        <div class="flex flex-col items-center justify-center gap-2 py-6 text-center">
            <div class="rounded-full bg-muted p-3">
                <component :is="icon" class="size-5 text-muted-foreground" aria-hidden="true" />
            </div>
            <p class="text-sm font-medium">Arrives in Phase {{ phase }}</p>
            <p v-if="description" class="max-w-xs text-xs text-muted-foreground">{{ description }}</p>
        </div>
    </Card>
</template>
