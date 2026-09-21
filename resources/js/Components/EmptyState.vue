<script setup lang="ts">
import { X } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        /** A lucide icon component. */
        icon: Component;
        title: string;
        /** One line of help text. */
        description?: string;
        /**
         * `empty` — nothing exists yet. `filtered` — records exist but none match the
         * current filters, so the way out is clearing them. `error` — the load failed.
         */
        variant?: 'empty' | 'filtered' | 'error';
    }>(),
    { variant: 'empty' },
);

const emit = defineEmits<{ clear: [] }>();

/** The icon medallion: muted by default, destructive-toned when something went wrong. */
const medallionClass = computed(() =>
    props.variant === 'error' ? 'bg-destructive/10 text-destructive' : 'bg-muted text-muted-foreground',
);

/** `filtered` offers a way out even when the caller passes no action of its own. */
const showsDefaultAction = computed(() => props.variant === 'filtered');
</script>

<template>
    <div class="flex flex-col items-center gap-3 px-4 py-10 text-center">
        <span :class="cn('rounded-full p-3', medallionClass)">
            <component :is="icon" class="size-5" aria-hidden="true" />
        </span>
        <div class="flex flex-col gap-1">
            <p class="text-sm font-medium">{{ title }}</p>
            <p v-if="description" class="text-xs text-muted-foreground">{{ description }}</p>
        </div>
        <div v-if="$slots.action || showsDefaultAction" class="mt-1">
            <slot name="action">
                <Button v-if="showsDefaultAction" variant="outline" size="sm" @click="emit('clear')">
                    <X aria-hidden="true" />
                    Clear filters
                </Button>
            </slot>
        </div>
    </div>
</template>
