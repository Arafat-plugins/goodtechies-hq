<script setup lang="ts">
import AttachmentCard from '@/Components/Messages/AttachmentCard.vue';
import type { ThreadAttachment } from '@/Components/Messages/messages';

/**
 * Several pictures sent in one message, side by side as equal tiles that wrap onto the next
 * line — the client's reference (2026-10-15), in a task's discussion and on the Messages page.
 * Each tile is a cropped preview; clicking one opens it full size in the lightbox, with the
 * conversation's other pictures a swipe away (AttachmentCard owns that). Files that are not
 * pictures are not drawn here: they keep their own cards under the tiles.
 */
defineProps<{
    files: ThreadAttachment[];
}>();
</script>

<template>
    <ul class="flex min-w-0 flex-wrap gap-2" :aria-label="`${files.length} photos`">
        <li v-for="file in files" :key="file.id" class="h-32 w-44 max-w-full overflow-hidden rounded-lg border">
            <AttachmentCard :file="file" inline bare thumb />
        </li>
    </ul>
</template>
