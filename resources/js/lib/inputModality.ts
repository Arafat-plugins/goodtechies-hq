/**
 * Which device the person last pressed with, written to `<html data-input-modality>` as
 * `pointer` or `keyboard` (2026-09-29; the rule that reads it is at the end of app.css).
 *
 * Why it exists: a menu, select or command item paints an inset `--ring` on its highlighted state
 * — the KEYBOARD's indicator (DESIGN.md §2.2). But reka highlights an item for the pointer too,
 * and a Select opened by a click focuses its selected row, which Chromium then reports as
 * `:focus-visible` (focus came from script, from `<body>`), so CSS alone cannot tell the two
 * apart. The client saw that as an orange border on every dropdown they clicked. `app.css` drops
 * the ring under `[data-input-modality="pointer"]` and leaves `bg-accent` to mark the row; the
 * first key pressed puts it back.
 *
 * Until either event fires the attribute is absent and the ring behaves as it always has, so a
 * failure here can only ever show MORE focus, never less.
 */
export type InputModality = 'pointer' | 'keyboard';

export const INPUT_MODALITY_ATTRIBUTE = 'data-input-modality';

let installed = false;

export function trackInputModality(root: HTMLElement = document.documentElement): void {
    if (installed) {
        return;
    }
    installed = true;

    const set = (modality: InputModality) => {
        if (root.getAttribute(INPUT_MODALITY_ATTRIBUTE) !== modality) {
            root.setAttribute(INPUT_MODALITY_ATTRIBUTE, modality);
        }
    };

    // Capture phase, so a handler that stops propagation cannot hide the event from us.
    window.addEventListener('pointerdown', () => set('pointer'), { capture: true, passive: true });
    window.addEventListener('keydown', () => set('keyboard'), { capture: true, passive: true });
}
