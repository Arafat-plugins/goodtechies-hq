import type { InjectionKey } from 'vue';

/**
 * Brief 016: how a composer pinned to the bottom of a `DetailDrawer` tells the drawer how much
 * of its scrolling body it now covers.
 *
 * The pinned composer (`MessageThread`'s `composerPlacement="footer"`) stays where it is in the
 * component tree — one instance, so the text being typed and the thread's live updates are never
 * split or duplicated — and is drawn `absolute bottom-0` against the drawer's panel, which is the
 * nearest positioned ancestor and sits OUTSIDE the scroll container, so it does not scroll. The
 * drawer only has to pad its body by the composer's height, which grows with the textarea, the
 * attachment chip and the recording strip. This is that height, reported in pixels; 0 releases it.
 */
export const DRAWER_FOOTER_INSET: InjectionKey<(px: number) => void> = Symbol('drawer-footer-inset');
