<script setup lang="ts">
/**
 * Brief 010: the online dot — the success status token (`bg-status-done`, DESIGN.md), ringed in
 * the background colour so it reads on top of an avatar. Drawn only when online, and it says
 * "online" to a screen reader, so the fact is never the colour alone. The parent positions it.
 */
defineProps<{ online: boolean }>();
</script>

<template>
    <span v-if="online" class="block size-2.5 rounded-full bg-status-done ring-2 ring-background">
        <span class="sr-only">online</span>
    </span>
</template>
