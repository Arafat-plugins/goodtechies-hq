<script setup lang="ts">
import { ChevronLeft, ChevronRight, Download, RotateCcw, X, ZoomIn, ZoomOut } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import type { GalleryImage } from '@/Components/Messages/gallery';
import type { View } from '@/Components/Messages/lightboxZoom';
import { clampPan, IDENTITY, MAX_SCALE, wheelFactor, zoomAround } from '@/Components/Messages/lightboxZoom';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

/**
 * Brief 013: a picture opens on this page, never in a new tab.
 *
 * Polish 030: inside a chat it is a gallery — every picture in the conversation, oldest first.
 * ← / → (keys, buttons or a swipe) move to the previous or next one, and "3 / 12" says where
 * you are. Opened from anywhere without a gallery, it shows the one picture as before.
 *
 * Polish 043: a full-screen viewer, like Telegram's. The arrows sit at the screen's edges, far
 * from the picture. The picture zooms: double-click / double-tap, Ctrl + wheel or pinch, the
 * + / − buttons or keys (0 resets); a zoomed picture follows the wheel too and is dragged to
 * look around. The plain wheel on an unzoomed picture still moves between pictures (031).
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

const current = computed(() => items.value[Math.min(index.value, items.value.length - 1)] ?? items.value[0]);
const many = computed(() => items.value.length > 1);
const hasPrevious = computed(() => index.value > 0);
const hasNext = computed(() => index.value < items.value.length - 1);

/* ------------------------------------------------------------------ zoom (043) */

const stageEl = ref<HTMLElement | null>(null);
const imageEl = ref<HTMLImageElement | null>(null);
const view = ref<View>({ ...IDENTITY });
const zoomed = computed(() => view.value.scale > 1.001);
const panning = ref(false);

function resetZoom(): void {
    view.value = { ...IDENTITY };
}

/** A client point as stage-centre coordinates (what the zoom maths speaks). */
function fromStageCentre(clientX: number, clientY: number): { x: number; y: number } {
    const box = stageEl.value?.getBoundingClientRect();

    return box ? { x: clientX - box.left - box.width / 2, y: clientY - box.top - box.height / 2 } : { x: 0, y: 0 };
}

function settle(next: View): View {
    const stage = stageEl.value?.getBoundingClientRect();
    const image = imageEl.value;

    if (!stage || !image) {
        return next;
    }

    return clampPan(next, { width: image.offsetWidth, height: image.offsetHeight }, { width: stage.width, height: stage.height });
}

function zoomTo(scale: number, at = { x: 0, y: 0 }): void {
    view.value = settle(zoomAround(view.value, scale, at));
}

function zoomIn(): void {
    zoomTo(view.value.scale * 1.5);
}

function zoomOut(): void {
    zoomTo(view.value.scale / 1.5);
}

const percent = computed(() => `${Math.round(view.value.scale * 100)}%`);

/** Every time it opens, it opens on the picture that was clicked, unzoomed. */
watch(open, (isOpen) => {
    if (isOpen) {
        const at = items.value.findIndex((item) => item.id === props.imageId);
        index.value = at >= 0 ? at : 0;
        resetZoom();
    }
});

watch(index, () => resetZoom());

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
    } else if (event.key === '+' || event.key === '=') {
        event.preventDefault();
        zoomIn();
    } else if (event.key === '-' || event.key === '_') {
        event.preventDefault();
        zoomOut();
    } else if (event.key === '0') {
        event.preventDefault();
        resetZoom();
    }
}

/**
 * The wheel: Ctrl/⌘ + wheel (and a trackpad pinch, which arrives as one) zooms at the cursor,
 * and so does the plain wheel once the picture is zoomed. On an unzoomed picture the plain
 * wheel moves through the pictures — one step per gesture (031).
 */
let wheelLockedUntil = 0;

function onWheel(event: WheelEvent): void {
    event.preventDefault();

    if (event.ctrlKey || event.metaKey || zoomed.value) {
        zoomTo(view.value.scale * wheelFactor(event.deltaY), fromStageCentre(event.clientX, event.clientY));

        return;
    }

    if (!many.value) {
        return;
    }

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

function onDoubleClick(event: MouseEvent): void {
    if (zoomed.value) {
        resetZoom();
    } else {
        zoomTo(2.5, fromStageCentre(event.clientX, event.clientY));
    }
}

/*
 * Pointers: one pointer drags a zoomed picture (or, unzoomed, swipes to the next one on a
 * phone); two pointers pinch. A quick second tap on a phone is the double-tap zoom.
 */
const pointers = new Map<number, { x: number; y: number }>();
let dragFrom: { x: number; y: number; viewX: number; viewY: number } | null = null;
let pinchFrom: { distance: number; scale: number } | null = null;
let swipeFrom: { x: number; y: number } | null = null;
let lastTap = 0;

function distance(): number {
    const [a, b] = [...pointers.values()];

    return a && b ? Math.hypot(a.x - b.x, a.y - b.y) : 0;
}

function midpoint(): { x: number; y: number } {
    const [a, b] = [...pointers.values()];

    return a && b ? fromStageCentre((a.x + b.x) / 2, (a.y + b.y) / 2) : { x: 0, y: 0 };
}

function onPointerDown(event: PointerEvent): void {
    if (event.button !== 0 && event.pointerType === 'mouse') {
        return;
    }

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);

    if (pointers.size === 2) {
        pinchFrom = { distance: distance(), scale: view.value.scale };
        dragFrom = null;
        swipeFrom = null;

        return;
    }

    if (zoomed.value) {
        dragFrom = { x: event.clientX, y: event.clientY, viewX: view.value.x, viewY: view.value.y };
        panning.value = true;
    } else {
        swipeFrom = { x: event.clientX, y: event.clientY };
    }
}

function onPointerMove(event: PointerEvent): void {
    if (!pointers.has(event.pointerId)) {
        return;
    }

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pinchFrom && pointers.size === 2) {
        const now = distance();

        if (pinchFrom.distance > 0) {
            zoomTo((pinchFrom.scale * now) / pinchFrom.distance, midpoint());
        }

        return;
    }

    if (dragFrom) {
        view.value = settle({
            scale: view.value.scale,
            x: dragFrom.viewX + event.clientX - dragFrom.x,
            y: dragFrom.viewY + event.clientY - dragFrom.y,
        });
    }
}

function onPointerUp(event: PointerEvent): void {
    pointers.delete(event.pointerId);

    if (pointers.size < 2) {
        pinchFrom = null;
    }

    if (pointers.size === 0) {
        dragFrom = null;
        panning.value = false;
    }

    if (swipeFrom && event.pointerType !== 'mouse') {
        const moved = event.clientX - swipeFrom.x;
        const still = Math.abs(moved) < 10 && Math.abs(event.clientY - swipeFrom.y) < 10;
        swipeFrom = null;

        if (moved > 40) {
            previous();
        } else if (moved < -40) {
            next();
        } else if (still) {
            const now = Date.now();

            if (now - lastTap < 300) {
                zoomTo(2.5, fromStageCentre(event.clientX, event.clientY));
                lastTap = 0;
            } else {
                lastTap = now;
            }
        }
    }

    swipeFrom = null;
}

/** A zoomed picture that loads at a new size keeps inside the stage. */
function onImageLoad(): void {
    void nextTick(() => {
        view.value = settle(view.value);
    });
}

function onCloseAutoFocus(event: Event): void {
    event.preventDefault();
    emit('closed');
}

const control =
    'flex size-10 items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70 focus-visible:ring-3 focus-visible:ring-white/70 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-30';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="inset-0 top-0 left-0 flex h-svh w-screen max-w-none translate-x-0 translate-y-0 flex-col gap-0 rounded-none border-0 bg-black/95 p-0 text-white shadow-none sm:max-w-none"
            data-testid="image-lightbox"
            @close-auto-focus="onCloseAutoFocus"
            @keydown="onKeydown"
        >
            <DialogTitle class="sr-only">{{ current.name }}</DialogTitle>
            <DialogDescription class="sr-only">
                Image attachment<template v-if="many">, {{ index + 1 }} of {{ items.length }}. Use the left and right arrow keys for the others</template>. Double-click or press plus to zoom, minus to zoom out, 0 to reset. Press Escape to close.
            </DialogDescription>

            <!-- Top bar: name, where you are, zoom, download, close. -->
            <div class="flex min-w-0 shrink-0 items-center gap-2 px-3 py-2 sm:px-4">
                <span class="min-w-0 flex-1 truncate text-sm font-medium" :title="current.name">{{ current.name }}</span>
                <span v-if="many" class="shrink-0 text-xs text-white/70 tabular-nums">{{ index + 1 }} / {{ items.length }}</span>

                <div class="flex shrink-0 items-center gap-1">
                    <button type="button" :class="cn(control, 'size-9')" :disabled="!zoomed" aria-label="Zoom out" data-testid="lightbox-zoom-out" @click="zoomOut">
                        <ZoomOut class="size-4" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        class="hidden h-9 min-w-14 items-center justify-center rounded-full px-2 text-xs text-white/80 tabular-nums hover:bg-black/50 focus-visible:ring-3 focus-visible:ring-white/70 focus-visible:outline-none sm:flex"
                        :aria-label="`Zoom ${percent}. Reset`"
                        data-testid="lightbox-zoom-level"
                        @click="resetZoom"
                    >
                        {{ percent }}
                    </button>
                    <button
                        type="button"
                        :class="cn(control, 'size-9')"
                        :disabled="view.scale >= MAX_SCALE"
                        aria-label="Zoom in"
                        data-testid="lightbox-zoom-in"
                        @click="zoomIn"
                    >
                        <ZoomIn class="size-4" aria-hidden="true" />
                    </button>
                    <button v-if="zoomed" type="button" :class="cn(control, 'size-9 sm:hidden')" aria-label="Reset zoom" @click="resetZoom">
                        <RotateCcw class="size-4" aria-hidden="true" />
                    </button>
                    <a :href="current.href" :download="current.name" :class="cn(control, 'size-9')" aria-label="Download">
                        <Download class="size-4" aria-hidden="true" />
                    </a>
                    <DialogClose :class="cn(control, 'size-9')" aria-label="Close">
                        <X class="size-4" aria-hidden="true" />
                    </DialogClose>
                </div>
            </div>

            <!-- The stage: the picture centred; the arrows at the screen's edges, never on it. -->
            <div
                ref="stageEl"
                :class="
                    cn(
                        'relative min-h-0 flex-1 touch-none overflow-hidden select-none',
                        zoomed ? (panning ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-zoom-in',
                    )
                "
                data-testid="lightbox-stage"
                @wheel="onWheel"
                @dblclick="onDoubleClick"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
            >
                <div class="absolute inset-0 flex items-center justify-center px-14 py-4 sm:px-20">
                    <img
                        ref="imageEl"
                        :key="current.id"
                        :src="current.src"
                        :alt="current.name"
                        draggable="false"
                        :class="cn('block max-h-full max-w-full object-contain will-change-transform', !panning && 'transition-transform duration-150 motion-reduce:transition-none')"
                        :style="{ transform: `translate(${view.x}px, ${view.y}px) scale(${view.scale})` }"
                        data-testid="lightbox-image"
                        @load="onImageLoad"
                    >
                </div>

                <button
                    v-if="many"
                    type="button"
                    :class="cn(control, 'absolute top-1/2 left-2 size-11 -translate-y-1/2 sm:left-4')"
                    :disabled="!hasPrevious"
                    aria-label="Previous image"
                    data-testid="lightbox-previous"
                    @pointerdown.stop
                    @dblclick.stop
                    @click="previous"
                >
                    <ChevronLeft class="size-6" aria-hidden="true" />
                </button>
                <button
                    v-if="many"
                    type="button"
                    :class="cn(control, 'absolute top-1/2 right-2 size-11 -translate-y-1/2 sm:right-4')"
                    :disabled="!hasNext"
                    aria-label="Next image"
                    data-testid="lightbox-next"
                    @pointerdown.stop
                    @dblclick.stop
                    @click="next"
                >
                    <ChevronRight class="size-6" aria-hidden="true" />
                </button>
            </div>
        </DialogContent>
    </Dialog>
</template>
