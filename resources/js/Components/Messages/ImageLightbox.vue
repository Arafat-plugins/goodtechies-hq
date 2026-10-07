<script setup lang="ts">
import { ChevronLeft, ChevronRight, Download } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { GalleryImage } from '@/Components/Messages/gallery';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

/**
 * Brief 013: a picture opens on this page, never in a new tab.
 *
 * Polish 030: inside a chat it is a gallery — every picture in the conversation, oldest first.
 * ← / → (keys, buttons or a swipe) move to the previous or next one, and "3 / 12" says where
 * you are. Opened from anywhere without a gallery, it shows the one picture as before.
 */
const props = defineProps<{
    src: string;
    name: string;
    /** The signed link the Download control saves from. */
    href: string;
    /** This picture's file id, to find it in the gallery. */
    imageId?: number;
    gallery?: GalleryImage[];
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    /** The dialog has closed: the opener puts focus back on itself. */
    closed: [];
}>();

const items = computed<GalleryImage[]>(() => {
    const list = props.gallery ?? [];

    if (props.imageId !== undefined && list.some((item) => item.id === props.imageId)) {
        return list;
    }

    return [{ id: props.imageId ?? 0, src: props.src, name: props.name, href: props.href }];
});

const index = ref(0);

/** Every time it opens, it opens on the picture that was clicked. */
watch(open, (isOpen) => {
    if (isOpen) {
        const at = items.value.findIndex((item) => item.id === props.imageId);
        index.value = at >= 0 ? at : 0;
    }
});

const current = computed(() => items.value[Math.min(index.value, items.value.length - 1)] ?? items.value[0]);
const many = computed(() => items.value.length > 1);
const hasPrevious = computed(() => index.value > 0);
const hasNext = computed(() => index.value < items.value.length - 1);

function previous(): void {
    if (hasPrevious.value) {
        index.value -= 1;
    }
}

function next(): void {
    if (hasNext.value) {
        index.value += 1;
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        previous();
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        next();
    }
}

/** A horizontal swipe of 40 px or more on a phone. */
let touchX: number | null = null;

function onTouchStart(event: TouchEvent): void {
    touchX = event.touches[0]?.clientX ?? null;
}

function onTouchEnd(event: TouchEvent): void {
    const endX = event.changedTouches[0]?.clientX;

    if (touchX === null || endX === undefined) {
        return;
    }

    const moved = endX - touchX;
    touchX = null;

    if (moved > 40) {
        previous();
    } else if (moved < -40) {
        next();
    }
}

/**
 * Polish 031: the mouse wheel moves through the pictures — down/right is next, up/left is
 * previous. One step per gesture: a trackpad fires dozens of wheel events for one swipe.
 */
let wheelLockedUntil = 0;

function onWheel(event: WheelEvent): void {
    if (!many.value) {
        return;
    }

    event.preventDefault();

    const now = Date.now();
    const delta = Math.abs(event.deltaY) >= Math.abs(event.deltaX) ? event.deltaY : event.deltaX;

    if (now < wheelLockedUntil || Math.abs(delta) < 4) {
        return;
    }

    wheelLockedUntil = now + 350;

    if (delta > 0) {
        next();
    } else {
        previous();
    }
}

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
            @keydown="onKeydown"
        >
            <DialogTitle class="sr-only">{{ current.name }}</DialogTitle>
            <DialogDescription class="sr-only">
                Image attachment<template v-if="many">, {{ index + 1 }} of {{ items.length }}. Use the left and right arrow keys or the mouse wheel for the others</template>. Press Escape to close.
            </DialogDescription>

            <!-- Polish 031: the arrows sit in their own gutters beside the picture, never on it. -->
            <div
                :class="cn('relative flex min-h-0 w-full items-center justify-center', many && 'px-14')"
                @touchstart.passive="onTouchStart"
                @touchend="onTouchEnd"
                @wheel="onWheel"
            >
                <img
                    :key="current.id"
                    :src="current.src"
                    :alt="current.name"
                    class="block min-h-0 max-h-[calc(100vh-9rem)] w-auto max-w-full shrink rounded-md object-contain"
                >

                <button
                    v-if="many"
                    type="button"
                    class="absolute top-1/2 left-0 flex size-10 -translate-y-1/2 items-center justify-center rounded-full border bg-background/90 text-foreground hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none disabled:opacity-0"
                    :disabled="!hasPrevious"
                    aria-label="Previous image"
                    data-testid="lightbox-previous"
                    @click="previous"
                >
                    <ChevronLeft class="size-5" aria-hidden="true" />
                </button>
                <button
                    v-if="many"
                    type="button"
                    class="absolute top-1/2 right-0 flex size-10 -translate-y-1/2 items-center justify-center rounded-full border bg-background/90 text-foreground hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none disabled:opacity-0"
                    :disabled="!hasNext"
                    aria-label="Next image"
                    data-testid="lightbox-next"
                    @click="next"
                >
                    <ChevronRight class="size-5" aria-hidden="true" />
                </button>
            </div>

            <div class="flex w-full min-w-0 items-center justify-between gap-3">
                <span class="min-w-0 truncate text-sm font-medium" :title="current.name">{{ current.name }}</span>
                <span v-if="many" class="shrink-0 text-xs text-muted-foreground tabular-nums">
                    {{ index + 1 }} / {{ items.length }}
                </span>
                <a
                    :href="current.href"
                    :download="current.name"
                    class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Download class="size-4" aria-hidden="true" />
                    Download
                </a>
            </div>
        </DialogContent>
    </Dialog>
</template>
