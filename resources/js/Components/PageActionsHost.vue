<script setup lang="ts">
import { inject, onBeforeUnmount, onMounted, ref } from 'vue';
import { PAGE_ACTIONS } from '@/lib/pageActions';

/**
 * Where the page's `#actions` land in a toolbar row (polish 007). Put it LAST in the page's
 * first row of controls; it pushes itself to the end of the row. Outside a `PageShell`, or when
 * another host already claimed the actions, it renders an empty, zero-width element.
 */
const slot = inject(PAGE_ACTIONS, null);
const el = ref<HTMLElement | null>(null);
const claimed = ref(false);

onMounted(() => {
    if (slot && el.value) {
        claimed.value = slot.claim(el.value);
    }
});

onBeforeUnmount(() => {
    if (slot && el.value && claimed.value) {
        slot.release(el.value);
    }
});
</script>

<template>
    <div
        ref="el"
        :class="claimed ? 'ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2' : 'hidden'"
        data-page-actions-host
    />
</template>
