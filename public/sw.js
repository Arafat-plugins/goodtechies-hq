/*
 * goodERP service worker — an offline fallback page and push notifications.
 *
 * It deliberately caches NO application page, script or style: every screen is always fetched
 * live from the server, so whatever is deployed to the VPS is what the web app and the Android
 * app show the next time they load. Only full-page navigations are touched (Inertia visits are
 * XHR and pass straight through); when one fails because the device is offline, the small
 * static /offline.html is shown instead of the browser's error page.
 *
 * Bump CACHE when offline.html or the precache list changes.
 *
 * Push: shows what the server sent (App\Services\PushService) and opens its link when tapped.
 */
const CACHE = 'gooderp-offline-v1';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/brand/mark.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_URL)));
});

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = { body: event.data ? event.data.text() : '' };
    }

    const options = {
        body: data.body || '',
        icon: '/brand/icon-192.png',
        badge: '/brand/badge-96.png',
        data: { url: data.url || '/' },
    };

    // Several messages from one chat replace each other instead of piling up.
    if (data.tag) {
        options.tag = data.tag;
        options.renotify = true;
    }

    event.waitUntil(self.registration.showNotification(data.title || 'goodERP', options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;
    // Only ever open goodERP itself, whatever the push said.
    const safeTarget = new URL(target).origin === self.location.origin ? target : self.location.origin + '/';

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            for (const client of windows) {
                if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                    await client.focus();

                    if ('navigate' in client) {
                        try {
                            await client.navigate(safeTarget);
                        } catch (error) {
                            /* A page this worker does not control cannot be navigated; it is focused. */
                        }
                    }

                    return;
                }
            }

            await self.clients.openWindow(safeTarget);
        })(),
    );
});
