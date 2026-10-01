/*
 * goodERP service worker — an offline fallback page, nothing else.
 *
 * It deliberately caches NO application page, script or style: every screen is always fetched
 * live from the server, so whatever is deployed to the VPS is what the web app and the Android
 * app show the next time they load. Only full-page navigations are touched (Inertia visits are
 * XHR and pass straight through); when one fails because the device is offline, the small
 * static /offline.html is shown instead of the browser's error page.
 *
 * Bump CACHE when offline.html or the precache list changes.
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
