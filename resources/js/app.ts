import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { clearAllDrafts, clearDraftsNotOwnedBy } from '@/lib/drafts';
import {
    currentUserVisit,
    isUserVisitUrl,
    noteUserVisitFinish,
    noteUserVisitStart,
    reportNetworkFailure,
    reportNetworkSuccess,
    TOO_LARGE_TEXT,
    type UserVisit,
} from '@/lib/net';
import { noteRefusedVisit, reportStatus, trackVisitFinish, trackVisitStart } from '@/lib/session';
import { toast } from '@/lib/toast';
import { disarmUnsavedGuard } from '@/lib/unsavedGuard';
import { isVersionAnswer, markNewVersion } from '@/lib/version';

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

/**
 * Session expiry (reliability slice 1), registered once for the whole app. A 401 or 419 on any
 * Inertia visit — a click or a background `router.reload()` — and a 403 that says the person's
 * surface changed are the session module's: Inertia's raw error modal is prevented and
 * `SessionEndedDialog` speaks instead, over the page, with whatever was typed left in place.
 * Every other status keeps Inertia's own behaviour.
 */
/**
 * Reliability slice 3: signing out clears every composer draft this tab holds (`lib/drafts.ts`),
 * from whichever control sends it — the user menu or the enrolment screen's escape hatch.
 */
router.on('before', (event) => {
    const { visit } = event.detail;

    if (visit.method === 'post' && visit.url.pathname === '/logout') {
        clearAllDrafts();
        disarmUnsavedGuard();
    }
});

router.on('start', (event) => {
    trackVisitStart(event.detail.visit);
    watchSlowVisit(noteUserVisitStart(event.detail.visit));
});
router.on('finish', (event) => {
    trackVisitFinish(event.detail.visit);
    noteUserVisitFinish(event.detail.visit);
});

/**
 * Network failures and server errors (reliability slice 2a), in the same listener set.
 *
 * A visit the PERSON started (a Save, a link) that gets no answer, a 5xx, a 413 or a 429 is told
 * so in plain words, and the page stays exactly as it was — a `useForm` keeps its data on a
 * failed visit, so whatever was typed is still there to send again. A BACKGROUND visit (a poll's
 * partial reload) says nothing: `lib/net.ts` counts it, the poll backs off, and the shell's
 * connectivity strip speaks once for all of them. In both cases `preventDefault()` keeps
 * Inertia's raw error modal (Laravel's HTML in an iframe) from ever showing.
 *
 * A 403 / 404 / 429 / 500 / 503 on a person's GET arrives as the `Shared/Error` page and is left
 * to render: that IS the answer. On a Save it is a toast instead, so the form is not swapped out
 * from under the person.
 */
const NET_TEXT = {
    saveNetwork: "Couldn't reach goodERP, so nothing was saved. Check your connection and try again.",
    navigateNetwork: "Couldn't open that page. Check your connection and try again.",
    saveServer: 'Something went wrong on our side, so nothing was saved. Try again in a moment.',
    tooLarge: TOO_LARGE_TEXT,
    rateLimited: 'Too many attempts. Wait a minute and try again.',
    slow: 'Still working — the connection is slow.',
} as const;

/** A person's visit still running after this long says so, once. It is never aborted. */
const SLOW_AFTER_MS = 15_000;

let slowTimer: ReturnType<typeof setTimeout> | null = null;

function watchSlowVisit(visit: UserVisit | null): void {
    if (visit === null) {
        return;
    }

    if (slowTimer !== null) {
        clearTimeout(slowTimer);
    }

    slowTimer = setTimeout(() => {
        slowTimer = null;

        if (currentUserVisit() === visit) {
            toast.info(NET_TEXT.slow);
        }
    }, SLOW_AFTER_MS);
}

function reasonOf(data: unknown): string | null {
    if (typeof data === 'object' && data !== null) {
        const reason = (data as { reason?: unknown }).reason;

        return typeof reason === 'string' ? reason : null;
    }

    if (typeof data === 'string' && data.trim().startsWith('{')) {
        try {
            return reasonOf(JSON.parse(data));
        } catch {
            return null;
        }
    }

    return null;
}

function isInertiaPage(data: unknown): boolean {
    return typeof data === 'object' && data !== null && typeof (data as { component?: unknown }).component === 'string';
}

// Any answer from the server — a page, or validation errors — means it is reachable.
router.on('success', () => reportNetworkSuccess());
router.on('error', () => reportNetworkSuccess());

router.on('networkError', (event) => {
    reportNetworkFailure();

    const visit = currentUserVisit();
    const url = (event.detail.error as { url?: string }).url;

    if (visit !== null && isUserVisitUrl(url)) {
        toast.error(visit.method === 'get' ? NET_TEXT.navigateNetwork : NET_TEXT.saveNetwork);
    }

    event.preventDefault();
});

router.on('httpException', (event) => {
    const { status, data } = event.detail.response;

    // Reliability slice 3: a background read met a new deploy. Not an error and not a network
    // failure — the polls stop and the shell offers "Reload now" (`lib/version.ts`).
    if (isVersionAnswer(status, reasonOf(data))) {
        markNewVersion();
        event.preventDefault();

        return;
    }

    // A Save (or any visit the person started) refused while the dialog is already up must not
    // vanish: the dialog is told to pull focus back to itself.
    if (status === 401 || status === 419) {
        noteRefusedVisit();
    }

    if (reportStatus(status, data)) {
        event.preventDefault();

        return;
    }

    if (status >= 500) {
        reportNetworkFailure();
    } else {
        reportNetworkSuccess();
    }

    const reason = reasonOf(data);
    const visit = currentUserVisit();
    const handled = status >= 500 || status === 413 || status === 429 || reason === 'error' || reason === 'too_large';

    // A poll's partial reload: the server says `{reason: "error"}` and never swaps the page.
    if (reason === 'error' || visit === null) {
        if (handled) {
            event.preventDefault();
        }

        return;
    }

    if (status === 413 || reason === 'too_large') {
        toast.error(NET_TEXT.tooLarge);
        event.preventDefault();

        return;
    }

    if (visit.method !== 'get') {
        if (status >= 500) {
            toast.error(NET_TEXT.saveServer);
            event.preventDefault();
        } else if (status === 429) {
            toast.error(NET_TEXT.rateLimited);
            event.preventDefault();
        }

        return;
    }

    // A GET the person started: the goodERP error page renders. Anything that is not one (a
    // proxy's 502 during a deploy, say) is not shown raw.
    if (handled && !isInertiaPage(data)) {
        toast.error(status === 429 ? NET_TEXT.rateLimited : NET_TEXT.navigateNetwork);
        event.preventDefault();
    }
});

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

        // Reliability slice 3: a draft left in this tab by somebody else (or by anybody, when
        // nobody is signed in) is deleted before any composer could read it.
        clearDraftsNotOwnedBy(
            (props.initialPage.props as { auth?: { user?: { id?: number } | null } }).auth?.user?.id ?? null,
        );

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: 'var(--ring)',
    },
});
