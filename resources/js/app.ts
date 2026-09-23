import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

/**
 * The product name, taken from the server at runtime rather than baked in at build time.
 *
 * It used to read VITE_APP_NAME, which Vite resolves out of `.env` while bundling — so the
 * title could only change by rebuilding, and only if that particular file was edited. The
 * shared `app.name` prop is `config('app.name')`, which is APP_NAME, which an environment
 * variable can override. Same value the sidebar reads, so the tab and the wordmark cannot
 * disagree, and no rebuild is needed to change it.
 *
 * `setup` runs before any client-side navigation, so `title` always has the real name by the
 * time it is called; the first page's title is server-rendered in app.blade.php anyway.
 */
let appName = 'goodERP';

const pages = import.meta.glob<{ default: DefineComponent }>('./Pages/**/*.vue');

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: async (name) => {
        const page = pages[`./Pages/${name}.vue`];

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return (await page()).default;
    },
    setup({ el, App, props, plugin }) {
        appName = (props.initialPage.props as { app?: { name?: string } }).app?.name ?? appName;

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: 'var(--ring)',
    },
});
