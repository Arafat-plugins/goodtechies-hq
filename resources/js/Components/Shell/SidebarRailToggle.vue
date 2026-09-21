<script setup lang="ts">
import { PanelLeftClose, PanelLeftOpen } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/**
 * The bottom-of-the-sidebar switch between the 256 px column and the 56 px icon rail.
 * The accessible name says which way it goes, because the icon alone does not: a viewer
 * on a rail hears "Expand sidebar", a viewer on the column hears "Collapse sidebar to icons".
 */
const props = defineProps<{
    rail: boolean;
}>();

defineEmits<{
    'update:rail': [boolean];
}>();

const label = computed(() => (props.rail ? 'Expand sidebar' : 'Collapse sidebar to icons'));
</script>

<template>
    <div :class="cn('shrink-0 border-t border-sidebar-border p-2', rail && 'px-1')">
        <button
            type="button"
            :aria-label="label"
            :title="label"
            :aria-pressed="rail"
            :class="
                cn(
                    'flex h-9 items-center gap-2 rounded-md text-sm text-sidebar-foreground/80 transition-colors',
                    'hover:bg-sidebar-border hover:text-sidebar-accent-foreground',
                    'focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none',
                    rail ? 'w-full justify-center' : 'w-full px-3',
                )
            "
            @click="$emit('update:rail', !rail)"
        >
            <component :is="rail ? PanelLeftOpen : PanelLeftClose" class="size-4 shrink-0" aria-hidden="true" />
            <span v-if="!rail" class="truncate">Collapse</span>
        </button>
    </div>
</template>
