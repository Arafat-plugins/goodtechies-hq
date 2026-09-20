<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    inverted?: boolean;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <span :class="cn('inline-flex items-center gap-2', props.class)">
        <span
            aria-hidden="true"
            class="flex size-6 shrink-0 items-center justify-center rounded-md bg-sidebar-primary"
        >
            <span class="size-2 rounded-sm bg-sidebar-primary-foreground" />
        </span>
        <span
            :class="
                cn(
                    'text-base font-semibold tracking-tight',
                    props.inverted ? 'text-sidebar-foreground' : 'text-primary',
                )
            "
        >
            GoodTechies HQ
        </span>
    </span>
</template>
