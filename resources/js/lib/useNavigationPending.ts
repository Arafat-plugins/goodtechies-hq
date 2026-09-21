import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onScopeDispose, readonly, ref } from 'vue';

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
