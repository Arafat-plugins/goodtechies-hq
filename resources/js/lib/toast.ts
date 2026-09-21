import type { ExternalToast } from 'vue-sonner';
import { toast as sonner } from 'vue-sonner';

export type ToastOptions = ExternalToast;
export type ToastId = string | number;

/** What a `promise()` call says at each stage. The success/error text may read the result. */
export interface PromiseMessages<T> {
    loading: string;
    success: string | ((value: T) => string);
    error: string | ((reason: unknown) => string);
}

/**
 * The app's toast API. Client-side feedback only — server flash messages keep
 * going through FlashMessage.vue, so nothing is announced twice.
 * `<Toaster />` must be mounted (it is, once per layout) for any of this to show.
 */
export const toast = {
    success(message: string, options?: ToastOptions): ToastId {
        return sonner.success(message, options);
    },

    error(message: string, options?: ToastOptions): ToastId {
        return sonner.error(message, options);
    },

    loading(message: string, options?: ToastOptions): ToastId {
        return sonner.loading(message, options);
    },

    /** One toast that runs loading → success or error alongside `promise`. */
    promise<T>(promise: Promise<T>, messages: PromiseMessages<T>): void {
        sonner.promise(promise, {
            loading: messages.loading,
            success: (value: T) =>
                typeof messages.success === 'function' ? messages.success(value) : messages.success,
            error: (reason: unknown) =>
                typeof messages.error === 'function' ? messages.error(reason) : messages.error,
        });
    },

    /** Dismiss one toast, or every toast when called with no id. */
    dismiss(id?: ToastId): void {
        sonner.dismiss(id);
    },
};
