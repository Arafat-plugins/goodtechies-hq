/**
 * Polish 043: the zoom maths for the chat image viewer (Telegram-style). Pure functions, so
 * Node's own test runner checks them.
 *
 * The picture is drawn centred in the stage with `transform: translate(x, y) scale(s)` and a
 * centre origin. Every point here is measured from the stage's centre.
 */
export const MIN_SCALE = 1;
export const MAX_SCALE = 5;

export interface View {
    scale: number;
    x: number;
    y: number;
}

export const IDENTITY: View = { scale: 1, x: 0, y: 0 };

export function clampScale(scale: number): number {
    return Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale));
}

/**
 * Keep the picture over the stage: when it is larger than the stage it may move until an edge
 * meets the stage's edge; when it is smaller on an axis it stays centred on that axis.
 *
 * `image` is the picture's size at scale 1, `stage` the viewer's size.
 */
export function clampPan(view: View, image: { width: number; height: number }, stage: { width: number; height: number }): View {
    const maxX = Math.max(0, (image.width * view.scale - stage.width) / 2);
    const maxY = Math.max(0, (image.height * view.scale - stage.height) / 2);

    return {
        scale: view.scale,
        x: Math.min(maxX, Math.max(-maxX, view.x)),
        y: Math.min(maxY, Math.max(-maxY, view.y)),
    };
}

/**
 * Zoom to `nextScale` keeping the picture's point under `at` (stage-centre coordinates) under
 * it — the cursor, the pinch's midpoint, or the double-clicked spot. Back at scale 1 the
 * picture is centred again.
 */
export function zoomAround(view: View, nextScale: number, at: { x: number; y: number }): View {
    const scale = clampScale(nextScale);

    if (scale === MIN_SCALE) {
        return { ...IDENTITY };
    }

    const ratio = scale / view.scale;

    return {
        scale,
        x: at.x - (at.x - view.x) * ratio,
        y: at.y - (at.y - view.y) * ratio,
    };
}

/** One wheel notch's zoom factor: smooth for trackpads, about ±20% for a mouse wheel notch. */
export function wheelFactor(deltaY: number): number {
    return Math.exp(-Math.max(-120, Math.min(120, deltaY)) * 0.0015);
}
