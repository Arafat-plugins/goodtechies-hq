<script setup lang="ts">
import { Download } from '@lucide/vue';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';

/**
 * Brief 013: an image in a message, opened in place rather than in a new tab.
 *
 * The `Dialog` brings Esc, the backdrop click, the close button and the focus trap. Focus goes
 * back to the thumbnail that opened it: the dialog is opened from state rather than from a
 * `DialogTrigger`, so reka has nothing to restore to and `closed` lets the card do it.
 */

defineProps<{
    src: string;
    name: string;
    /** The signed link the Download control saves from. */
    href: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    /** The dialog has closed: the opener puts focus back on itself. */
    closed: [];
}>();

function onCloseAutoFocus(event: Event): void {
    event.preventDefault();
    emit('closed');
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-screen w-fit flex-col items-center gap-3 p-4 pt-12 sm:max-w-[calc(100%-2rem)]"
            data-testid="image-lightbox"
            @close-auto-focus="onCloseAutoFocus"
        >
            <DialogTitle class="sr-only">{{ name }}</DialogTitle>
            <DialogDescription class="sr-only">Image attachment. Press Escape to close.</DialogDescription>

            <img
                :src="src"
                :alt="name"
                class="block min-h-0 max-h-screen w-auto max-w-full shrink rounded-md object-contain"
            >

            <div class="flex w-full min-w-0 items-center justify-between gap-3">
                <span class="min-w-0 truncate text-sm font-medium" :title="name">{{ name }}</span>
                <a
                    :href="href"
                    :download="name"
                    class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Download class="size-4" aria-hidden="true" />
                    Download
                </a>
            </div>
        </DialogContent>
    </Dialog>
</template>
