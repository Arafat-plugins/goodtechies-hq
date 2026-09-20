<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

const props = defineProps<{
    label: string;
    /** Real value. Omit it and set `phase` for a placeholder card. */
    value?: string | number;
    /** Sub-line; a placeholder defaults to "Arrives in Phase N". */
    sub?: string;
    phase?: number;
    icon?: Component;
}>();

const isPlaceholder = computed(() => props.value === undefined);
const subline = computed(() => props.sub ?? (props.phase ? `Arrives in Phase ${props.phase}` : ''));
</script>

<template>
    <Card class="gap-2 p-4 shadow-xs">
        <div class="flex items-center justify-between gap-2">
            <p class="truncate text-sm text-muted-foreground">{{ label }}</p>
            <component :is="icon" v-if="icon" class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        </div>
        <p :class="cn('text-3xl font-semibold tabular-nums', isPlaceholder && 'text-muted-foreground')">
            <template v-if="isPlaceholder">
                <span aria-hidden="true">—</span>
                <span class="sr-only">Not available yet</span>
            </template>
            <template v-else>{{ value }}</template>
        </p>
        <p v-if="subline" class="text-xs text-muted-foreground">{{ subline }}</p>
    </Card>
</template>
