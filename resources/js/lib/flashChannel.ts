import { usePage } from '@inertiajs/vue3';
import { computed, onScopeDispose, ref, watch } from 'vue';
import { toast } from './toast';

/**
 * Which channel announces the server's flash on this screen.
 *
 * DESIGN.md §5.19: server flash goes through `FlashMessage.vue`, client actions go through
 * `toast()`, and **nothing is announced twice**. A screen whose whole job is a stream of small
 * writes — the task detail, the task list with its drawer — wants the toaster instead: the
 * page's alert strip is at the top of a two-metre page, and a drawer covers it outright, so a
 * refusal rendered there is a refusal nobody sees.
 *
 * So the flash is *moved*, not copied. A screen calls `useFlashAsToast()`; for as long as it is
 * mounted it holds the claim, `FlashMessage` draws nothing, and every flash that arrives is
 * consumed — read, cleared off the page props, and spoken once by the toaster. The server's own
 * sentence is what gets said, which matters because the interesting flashes here are
 * `TaskStateException`'s refusals ("Completion needs Tapu's work summary — they are the primary
 * assignee"), not "something went wrong".
 *
 * Two mechanisms rather than one, on purpose. Clearing the props alone is a race against Vue's
 * render of an ancestor component — `FlashMessage` lives in the layout, above every page, so it
 * can paint first. The claim is what makes the suppression deterministic; the clearing is what
 * stops the message reappearing the moment the claim is released.
 *
 * The watcher is `flush: 'sync'`, which is what lets a caller ask what the server said: it runs
 * the instant Inertia reassigns the page, strictly before any visit callback, so by the time an
 * `onSuccess` runs, `lastFlash` already holds that visit's message.
 */

const claims = ref(0);

/** True while some mounted screen is taking its flashes to the toaster. */
export const flashClaimed = computed(() => claims.value > 0);

/** The most recently consumed flash, and a counter that only moves when one is. */
export const lastFlash: { success: string | null; error: string | null } = { success: null, error: null };
export const flashSeq = ref(0);

export function useFlashAsToast(): void {
    const page = usePage();

    claims.value += 1;

    watch(
        // Identity, not contents: a new page brings a new bag, and emptying this one below
        // must not re-enter the handler.
        () => page.props.flash,
        (flash) => {
            if (!flash) {
                return;
            }

            const { success, error } = flash;

            if (!success && !error) {
                return;
            }

            // Consumed: whatever renders the flash later finds an empty bag.
            flash.success = null;
            flash.error = null;

            lastFlash.success = success ?? null;
            lastFlash.error = error ?? null;
            flashSeq.value += 1;

            if (error) {
                toast.error(error);
            } else if (success) {
                toast.success(success);
            }
        },
        { immediate: true, flush: 'sync' },
    );

    onScopeDispose(() => {
        claims.value -= 1;
    });
}
