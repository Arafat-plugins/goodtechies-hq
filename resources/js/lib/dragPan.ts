import type { Ref } from 'vue';
import { onBeforeUnmount, readonly, ref, watch } from 'vue';

/**
 * Drag-to-pan for a horizontal scroller: press the left mouse button on empty background and
 * move, and the scroller follows the pointer — the ClickUp board gesture.
 *
 * - **Mouse only.** Touch and pen keep the browser's own scrolling; nothing here runs for them.
 * - **Never on something that is already a gesture.** A press that starts on a card, a link, a
 *   button, a field, a menu item or anything `draggable` is left alone, so HTML5 drag-and-drop and
 *   every click inside a card keep working. `PAN_EXCLUDE` is the whole list.
 * - **A pan is not a click.** It only starts after `PAN_THRESHOLD_PX` of movement, and the click
 *   that ends one is swallowed in the capture phase before any card can see it.
 * - **No inertia.** The scroller stops when the pointer stops.
 *
 * `isPanning` is exposed so a caller can hold anything that would re-render the scroller (a live
 * refresh) until the gesture ends.
 */

/** Presses that start on any of these never pan. */
export const PAN_EXCLUDE =
    'a, button, input, textarea, select, label, [contenteditable="true"], [role="button"], [role="menuitem"], [draggable="true"], [data-board-card]';

/** How far the pointer must travel before a press becomes a pan rather than a click. */
export const PAN_THRESHOLD_PX = 4;

/** The part of a pointer event the pan decisions read — so they can be tested without a DOM. */
export interface PanPress {
    pointerType: string;
    button: number;
    /** True when the press landed on (or inside) something in `PAN_EXCLUDE`. */
    onExcluded: boolean;
    /** True when the press landed on the scroller's own scrollbar. */
    onScrollbar: boolean;
}

/** Whether a press may become a pan. */
export function canStartPan(press: PanPress): boolean {
    return press.pointerType === 'mouse' && press.button === 0 && !press.onExcluded && !press.onScrollbar;
}

/** Whether the pointer has moved far enough from where it was pressed to count as a pan. */
export function passedThreshold(dx: number, dy: number, threshold = PAN_THRESHOLD_PX): boolean {
    return Math.max(Math.abs(dx), Math.abs(dy)) >= threshold;
}

/** The scroll position for a pointer that has moved `dx` since the press. The content follows it. */
export function panScrollLeft(startScrollLeft: number, dx: number): number {
    return startScrollLeft - dx;
}

/** A press on the scroller's own scrollbar strip, which belongs to the scrollbar, not to us. */
function onOwnScrollbar(scroller: HTMLElement, event: PointerEvent): boolean {
    if (event.target !== scroller) {
        return false;
    }

    const box = scroller.getBoundingClientRect();

    return (
        event.clientY - box.top - scroller.clientTop >= scroller.clientHeight ||
        event.clientX - box.left - scroller.clientLeft >= scroller.clientWidth
    );
}

export function useDragPan(scroller: Ref<HTMLElement | null>): { isPanning: Readonly<Ref<boolean>> } {
    const isPanning = ref(false);

    let press: { id: number; x: number; y: number; scrollLeft: number } | null = null;
    let swallowClick = false;
    let attached: HTMLElement | null = null;

    function onPointerDown(event: PointerEvent): void {
        const element = attached;

        if (element === null || !(event.target instanceof Element)) {
            return;
        }

        const allowed = canStartPan({
            pointerType: event.pointerType,
            button: event.button,
            onExcluded: event.target.closest(PAN_EXCLUDE) !== null,
            onScrollbar: onOwnScrollbar(element, event),
        });

        if (!allowed) {
            return;
        }

        press = { id: event.pointerId, x: event.clientX, y: event.clientY, scrollLeft: element.scrollLeft };
    }

    function onPointerMove(event: PointerEvent): void {
        const element = attached;

        if (element === null || press === null || event.pointerId !== press.id) {
            return;
        }

        // The button came up somewhere we never heard about (outside the window, before capture).
        if ((event.buttons & 1) === 0) {
            end(event);

            return;
        }

        const dx = event.clientX - press.x;

        if (!isPanning.value) {
            if (!passedThreshold(dx, event.clientY - press.y)) {
                return;
            }

            isPanning.value = true;
            window.getSelection()?.removeAllRanges();

            try {
                element.setPointerCapture(event.pointerId);
            } catch {
                // The pointer is already gone; the next `pointerup` ends the pan anyway.
            }
        }

        element.scrollLeft = panScrollLeft(press.scrollLeft, dx);
    }

    function end(event: PointerEvent): void {
        if (press === null || event.pointerId !== press.id) {
            return;
        }

        const element = attached;
        const panned = isPanning.value;

        press = null;
        isPanning.value = false;

        if (element !== null && element.hasPointerCapture(event.pointerId)) {
            element.releasePointerCapture(event.pointerId);
        }

        if (panned) {
            // The click (if any) is dispatched in the same task as this `pointerup`; after that
            // the flag must not swallow a real click, even when the release produced none.
            swallowClick = true;
            window.setTimeout(() => {
                swallowClick = false;
            }, 0);
        }
    }

    function onClick(event: MouseEvent): void {
        if (swallowClick) {
            swallowClick = false;
            event.preventDefault();
            event.stopPropagation();
        }
    }

    /** No text selection while a press may still become a pan, or while one runs. */
    function onSelectStart(event: Event): void {
        if (press !== null || isPanning.value) {
            event.preventDefault();
        }
    }

    function attach(element: HTMLElement): void {
        attached = element;
        element.addEventListener('pointerdown', onPointerDown);
        element.addEventListener('pointermove', onPointerMove);
        element.addEventListener('pointerup', end);
        element.addEventListener('pointercancel', end);
        element.addEventListener('lostpointercapture', end);
        element.addEventListener('click', onClick, true);
        document.addEventListener('selectstart', onSelectStart);
    }

    function detach(): void {
        const element = attached;

        if (element === null) {
            return;
        }

        element.removeEventListener('pointerdown', onPointerDown);
        element.removeEventListener('pointermove', onPointerMove);
        element.removeEventListener('pointerup', end);
        element.removeEventListener('pointercancel', end);
        element.removeEventListener('lostpointercapture', end);
        element.removeEventListener('click', onClick, true);
        document.removeEventListener('selectstart', onSelectStart);
        attached = null;
        press = null;
        isPanning.value = false;
    }

    // The scroller comes and goes with the board (an empty board has none), so follow the ref.
    watch(
        scroller,
        (element) => {
            detach();

            if (element !== null) {
                attach(element);
            }
        },
        { immediate: true, flush: 'post' },
    );

    onBeforeUnmount(detach);

    return { isPanning: readonly(isPanning) };
}
