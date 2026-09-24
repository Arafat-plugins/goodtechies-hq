/// <reference types="vite/client" />

declare module '*.vue' {
    import type { DefineComponent } from 'vue';

    const component: DefineComponent<object, object, unknown>;
    export default component;
}

/**
 * The realtime keys Vite bakes into the bundle at build time (Phase 6).
 *
 * They are declared rather than left to `vite/client`'s index signature so that a typo in
 * `VITE_REVERB_HOST` is a type error here instead of `undefined` at runtime, where it would
 * show up as a socket quietly trying to reach the page's own hostname.
 *
 * Every one of them is optional, because the polling build sets none of them — see
 * `resources/js/echo.ts`, which treats anything but the exact string `reverb` as polling.
 */
interface ImportMetaEnv {
    readonly VITE_REALTIME?: string;
    readonly VITE_REVERB_APP_KEY?: string;
    readonly VITE_REVERB_HOST?: string;
    readonly VITE_REVERB_PORT?: string;
    readonly VITE_REVERB_SCHEME?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
