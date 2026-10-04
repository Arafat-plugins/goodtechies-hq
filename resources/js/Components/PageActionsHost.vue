<script setup lang="ts">
import { inject, onBeforeUnmount, onMounted, ref } from 'vue';
import { PAGE_ACTIONS, PAGE_TABLE_TOOLS } from '@/lib/pageActions';

/**
 * Where the page's `#actions` land in a toolbar row (polish 007), and — just before them — the
 * first table's density / Columns controls (polish 013). Put it LAST in the page's first row of
 * controls; it pushes itself to the end of the row. Outside a `PageShell`, or when another host
 * already claimed the slots, it renders nothing visible.
 */
/** `inline`: the row already pushes this to the end (it follows another end-aligned control). */
const props = defineProps<{ inline?: boolean }>();

const actions = inject(PAGE_ACTIONS, null);
const tools = inject(PAGE_TABLE_TOOLS, null);
const actionsEl = ref<HTMLElement | null>(null);
const toolsEl = ref<HTMLElement | null>(null);
const claimedActions = ref(false);
const claimedTools = ref(false);

onMounted(() => {
    if (actions && actionsEl.value) {
        claimedActions.value = actions.claim(actionsEl.value);
    }

    if (tools && toolsEl.value) {
        claimedTools.value = tools.claimHost(toolsEl.value);
    }
});

onBeforeUnmount(() => {
    if (actions && actionsEl.value && claimedActions.value) {
        actions.release(actionsEl.value);
    }

    if (tools && toolsEl.value && claimedTools.value) {
        tools.releaseHost(toolsEl.value);
    }
});
</script>

<template>
    <div
        :class="
            claimedActions || claimedTools
                ? [props.inline ? '' : 'ml-auto', 'flex shrink-0 flex-wrap items-center justify-end gap-2']
                : 'hidden'
        "
    >
        <div ref="toolsEl" class="flex flex-wrap items-center gap-2 empty:hidden" data-table-tools-host />
        <div ref="actionsEl" class="flex flex-wrap items-center gap-2 empty:hidden" data-page-actions-host />
    </div>
</template>
