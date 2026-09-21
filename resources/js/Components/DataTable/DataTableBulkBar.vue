<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/Components/ui/button';

/**
 * The bar that appears above a table once rows are selected, and only then.
 *
 * It owns the count and the way out; the screen owns the verbs, through the default slot.
 */
const props = defineProps<{
    count: number;
    /** The word for one row: "3 projects selected". */
    noun?: string;
}>();

const emit = defineEmits<{ clear: [] }>();

const label = computed(() => {
    const noun = props.noun ?? 'row';

    return `${props.count} ${props.count === 1 ? noun : `${noun}s`} selected`;
});
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-3 rounded-xl border bg-accent px-4 py-2 text-accent-foreground"
        role="region"
        aria-label="Bulk actions"
    >
        <p class="text-sm font-medium tabular-nums" aria-live="polite">{{ label }}</p>

        <div class="flex flex-1 flex-wrap items-center justify-end gap-2">
            <slot />
            <Button type="button" variant="ghost" size="sm" @click="emit('clear')">
                <X aria-hidden="true" />
                Clear selection
            </Button>
        </div>
    </div>
</template>
