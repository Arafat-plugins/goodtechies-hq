/**
 * Push notifications on this device — the browser half.
 *
 * Asks the browser for notification permission, subscribes this device's service worker
 * (`public/sw.js`, which shows each push and opens its link when tapped) to push with the
 * server's VAPID key, and saves or removes that subscription on the server
 * (`POST` / `DELETE /push/subscriptions`). Whether a person wants messages and alerts at all is
 * a separate, per-person setting (`PUT /profile/push`); this module only deals with the device.
 *
 * In the Android app (a Trusted Web Activity) Chrome hands the permission prompt to Android, so
 * what the person sees is the phone's own "Allow notifications?" dialog.
 */

export type PushDeviceState = 'unsupported' | 'blocked' | 'off' | 'on';

export function pushSupported(): boolean {
    return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/**
 * Whether this page runs in the Android app's own WebView — the backup the app opens when
 * Chrome cannot take over. WebView has no push service, so notifications need Chrome mode.
 */
export function inAppWebView(): boolean {
    return typeof navigator !== 'undefined' && /; wv\)/.test(navigator.userAgent);
}

export async function deviceState(): Promise<PushDeviceState> {
    if (!pushSupported()) {
        return 'unsupported';
    }

    if (Notification.permission === 'denied') {
        return 'blocked';
    }

    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = await registration?.pushManager.getSubscription();

    return subscription && Notification.permission === 'granted' ? 'on' : 'off';
}

export async function turnOn(vapidPublicKey: string): Promise<PushDeviceState> {
    if (!pushSupported()) {
        return 'unsupported';
    }

    const permission = await Notification.requestPermission();

    if (permission === 'denied') {
        return 'blocked';
    }

    if (permission !== 'granted') {
        return 'off';
    }

    (await navigator.serviceWorker.getRegistration('/')) ?? (await navigator.serviceWorker.register('/sw.js'));
    const registration = await navigator.serviceWorker.ready;

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey) as BufferSource,
        }));

    const json = subscription.toJSON();
    const contentEncoding = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings?.includes(
        'aes128gcm',
    )
        ? 'aes128gcm'
        : 'aesgcm';

    const response = await fetch('/push/subscriptions', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys, content_encoding: contentEncoding }),
    });

    if (!response.ok) {
        await subscription.unsubscribe();
        throw new Error('save-failed');
    }

    return 'on';
}

export async function turnOff(): Promise<PushDeviceState> {
    if (!pushSupported()) {
        return 'unsupported';
    }

    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = await registration?.pushManager.getSubscription();

    if (subscription) {
        try {
            await fetch('/push/subscriptions', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ endpoint: subscription.endpoint }),
            });
        } catch {
            // A network error does not stop this device from unsubscribing.
        }

        await subscription.unsubscribe();
    }

    return 'off';
}

/**
 * Re-sends this device's existing subscription to the server. Run once per full page load so a
 * device follows whoever is signed in on it, and comes back after a password change forgot it.
 */
export async function refreshSubscription(): Promise<void> {
    if (!pushSupported() || Notification.permission !== 'granted') {
        return;
    }

    try {
        const registration = await navigator.serviceWorker.getRegistration('/');
        const subscription = await registration?.pushManager.getSubscription();

        if (!subscription) {
            return;
        }

        const json = subscription.toJSON();
        const contentEncoding = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings?.includes(
            'aes128gcm',
        )
            ? 'aes128gcm'
            : 'aesgcm';

        await fetch('/push/subscriptions', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys, content_encoding: contentEncoding }),
        });
    } catch {
        // Best effort: the next full page load tries again.
    }
}

/**
 * Turns push off on this device. Called before signing out so the next person on this browser
 * never sees this person's notifications; capped at 3 s so signing out is never stuck.
 */
export async function forgetThisDevice(): Promise<void> {
    await Promise.race([turnOff().catch(() => 'off'), new Promise((resolve) => setTimeout(resolve, 3000))]);
}

function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    const output = new Uint8Array(raw.length);

    for (let i = 0; i < raw.length; i++) {
        output[i] = raw.charCodeAt(i);
    }

    return output;
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}
