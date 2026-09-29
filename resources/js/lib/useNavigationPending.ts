import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, onScopeDispose, readonly, ref } from 'vue';
import { currentUserVisit } from '@/lib/net';

/**
 * How many mounted screens are drawing their own skeleton off `useNavigationPending()` right now.
 * The shell's generic busy state (`useShellNavigationPending`) stands down while this is above
 * zero, so a page that already shows the shape of what is coming is not also dimmed under it.
 */
const skeletonOwners = ref(0);

/**
 * True while an Inertia visit has been running for longer than `delay` ms.
 *
 * A page binds its skeleton to this, so a slow navigation shows the shape of the
 * next screen instead of freezing on the current one. Visits under `delay` never
 * flip it, so a fast page does not flash a skeleton on its way in.
 *
 * It lives here rather than in `app.ts` because it is per-screen state: `app.ts`
 * mounts one root and has nowhere to put a value each page needs to read.
 */
export function useNavigationPending(delay = 200): Readonly<Ref<boolean>> {
    const pending = ref(false);

    skeletonOwners.value += 1;
    onScopeDispose(() => {
        skeletonOwners.value -= 1;
    });

    let timer: ReturnType<typeof setTimeout> | null = null;

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    const stopStart = router.on('start', () => {
        clearTimer();
        timer = setTimeout(() => {
            pending.value = true;
        }, delay);
    });

    const stopFinish = router.on('finish', () => {
        clearTimer();
        pending.value = false;
    });

    onScopeDispose(() => {
        clearTimer();
        stopStart();
        stopFinish();
    });

    return readonly(pending);
}

/**
 * The shell's own loading state (slow-loading slice 6): true while a navigation the PERSON
 * started — a link, a Back, a filter — has been running longer than `delay` ms.
 *
 * It does not keep a third record of which visit is whose. `app.ts` already tells `lib/net.ts`
 * about every visit (reliability slice 2), and `currentUserVisit()` is non-null exactly while a
 * visit that is neither `async` nor `prefetch` is in flight — so a background poll, a
 * `router.reload()` and a prefetch never dim the page, and a poll finishing in the middle of a
 * slow click does not clear it. Only a GET counts: a Save keeps its own button state, and the
 * page it lands on is the answer.
 *
 * `app.ts` registers its listeners at import, before any layout mounts, so by the time these run
 * `net.ts` has already noted the start (or the finish) of the same visit.
 *
 * Stands down while a mounted screen draws its own skeleton (`useNavigationPending`).
 */
export function useShellNavigationPending(delay = 300): Readonly<Ref<boolean>> {
    const waiting = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    const stopStart = router.on('start', () => {
        const visit = currentUserVisit();

        if (visit === null || visit.method !== 'get') {
            return;
        }

        clearTimer();
        waiting.value = false;
        timer = setTimeout(() => {
            timer = null;

            // Still the person's visit, not one that already finished or was replaced by a Save.
            const still = currentUserVisit();
            waiting.value = still !== null && still.method === 'get';
        }, delay);
    });

    const stopFinish = router.on('finish', () => {
        // A background read finishing while the person's own visit is still out changes nothing.
        if (currentUserVisit() !== null) {
            return;
        }

        clearTimer();
        waiting.value = false;
    });

    onScopeDispose(() => {
        clearTimer();
        stopStart();
        stopFinish();
    });

    return computed(() => waiting.value && skeletonOwners.value === 0);
}
