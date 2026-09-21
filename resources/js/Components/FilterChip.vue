<script setup lang="ts">
import { X } from '@lucide/vue';

/**
 * One active filter: `Label: value ×`.
 *
 * Two buttons side by side rather than a button inside a button — the body reopens the
 * value picker, the × removes the filter, and each has its own accessible name.
 */
const props = defineProps<{
    /** The filter's name, e.g. "Client". */
    label: string;
    /** The chosen value, already formatted for reading. */
    value: string;
}>();

const emit = defineEmits<{ edit: []; remove: [] }>();
</script>

<template>
    <div class="inline-flex h-8 max-w-full min-w-0 items-center rounded-full border bg-muted/50 text-xs">
        <button
            type="button"
            class="flex min-w-0 items-center gap-1 rounded-l-full py-1 pr-1 pl-3 outline-none hover:bg-muted focus-visible:ring-3 focus-visible:ring-ring/50"
            :aria-label="`Change the ${props.label} filter, currently ${props.value}`"
            @click="emit('edit')"
        >
            <span class="shrink-0 text-muted-foreground">{{ label }}</span>
            <span class="truncate font-medium">{{ value }}</span>
        </button>
        <button
            type="button"
            class="flex h-full shrink-0 items-center rounded-r-full pr-2 pl-1 text-muted-foreground outline-none hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50"
            :aria-label="`Remove the ${props.label} filter`"
            @click="emit('remove')"
        >
            <X class="size-3.5" aria-hidden="true" />
        </button>
    </div>
</template>
