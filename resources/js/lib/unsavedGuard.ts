import { router } from '@inertiajs/vue3';
import { onScopeDispose } from 'vue';

/**
 * Unsaved input is never thrown away without asking (reliability slice 3).
 *
 * One composable, many forms. Each mounted form registers an `isDirty` question here; while any of
 * them answers yes:
 *
 * - **Closing, reloading or leaving the tab** gets the browser's own "Leave site?" prompt
 *   (`beforeunload` — the browser writes those words, not us).
 * - **A person's own Inertia navigation** (a sidebar link, a breadcrumb) is held until
 *   `window.confirm(UNSAVED_TEXT)` says yes. Cancel keeps the page and everything typed on it.
 *
 * Never blocked: a background read (`async` — a poll's `router.reload()`), a prefetch, and any
 * non-GET visit, which is either this form's own Save or another action the person chose on the
 * same page and does not navigate away from what they typed.
 *
 * The registry is module-scoped so two dirty forms on one screen ask ONCE, not twice.
 */

export const UNSAVED_TEXT = 'You have unsaved changes. Leave this page and lose them?';

const guards = new Set<() => boolean>();

let removeBefore: (() => void) | null = null;
let removeFinish: (() => void) | null = null;

/** The person already said "leave" for the visit in flight — do not ask again on its way out. */
let confirmedLeave = false;

export function anyUnsaved(): boolean {
    for (const isDirty of guards) {
        try {
            if (isDirty()) {
                return true;
            }
        } catch {
            // A guard that throws is not a reason to trap somebody on a page.
        }
    }

    return false;
}

function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (confirmedLeave || !anyUnsaved()) {
        return;
    }

    event.preventDefault();
    // Older Chromium and Safari still need a returnValue to show the prompt.
    event.returnValue = '';
}

interface VisitLike {
    method?: string;
    async?: boolean;
    prefetch?: boolean;
}

function install(): void {
    if (removeBefore !== null || typeof window === 'undefined') {
        return;
    }

    window.addEventListener('beforeunload', onBeforeUnload);

    removeBefore = router.on('before', (event) => {
        const visit = event.detail.visit as VisitLike;

        if (visit.async || visit.prefetch || (visit.method ?? 'get').toLowerCase() !== 'get') {
            return;
        }

        if (!anyUnsaved()) {
            return;
        }

        if (window.confirm(UNSAVED_TEXT)) {
            confirmedLeave = true;

            return;
        }

        event.preventDefault();
    });

    removeFinish = router.on('finish', () => {
        confirmedLeave = false;
    });
}

function uninstall(): void {
    if (typeof window !== 'undefined') {
        window.removeEventListener('beforeunload', onBeforeUnload);
    }

    removeBefore?.();
    removeFinish?.();
    removeBefore = null;
    removeFinish = null;
    confirmedLeave = false;
}

/**
 * Signing out (reliability slice 3): the person chose to leave, so nothing on this page may ask
 * again — not the redirect chain after `POST /logout`, and not a full reload a version 409 turns
 * it into, which would otherwise raise "Leave site?" over a page that is already signed out.
 * The registry is emptied rather than paused; the forms that registered are about to unmount.
 */
export function disarmUnsavedGuard(): void {
    guards.clear();
    confirmedLeave = true;
}

/**
 * Ask before this scope's unsaved input is thrown away.
 *
 * @param isDirty Is there input that differs from what the form opened with? For a `useForm`
 *                form that is `() => form.isDirty`; a successful Save must make it false
 *                (`form.reset()` / `form.defaults()`) before anything navigates.
 */
export function useUnsavedGuard(isDirty: () => boolean): void {
    if (typeof window === 'undefined') {
        return;
    }

    guards.add(isDirty);
    install();

    onScopeDispose(() => {
        guards.delete(isDirty);

        if (guards.size === 0) {
            uninstall();
        }
    });
}
