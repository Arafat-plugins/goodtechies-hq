/*
 * goodERP service worker — makes the app start like an installed app, keeps an offline page,
 * and shows push notifications.
 *
 * Speed (2026-10-04, "loading system are still slow . not like apps"): the server is in Dallas
 * and the team is in Bangladesh, so every download crosses the world. The app's own code and
 * styles (/build/assets/*) have a content hash in their names — a new deploy means new names —
 * so they are kept on the device and served from it instantly (cache-first), and every file of
 * the current build is fetched once in the background so no screen ever waits for its code.
 * Fonts are kept the same way. Pages and data are NEVER cached: every screen still shows what is
 * live on the server, so a deploy shows up on the next load exactly as before.
 *
 * Full-page navigations that fail offline get the small static /offline.html instead of the
 * browser's error page. Bump CACHE when offline.html or PRECACHE changes.
 *
 * Push: shows what the server sent (App\Services\PushService) and opens its link when tapped.
 */
const CACHE = 'gooderp-offline-v1';
const ASSETS = 'gooderp-assets-v1';
const FONTS = 'gooderp-fonts-v1';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/brand/mark.svg'];
const BUILD_PREFIX = '/build/assets/';
const REFRESH_EVERY_MS = 10 * 60 * 1000;

let lastAssetRefresh = 0;

/** Every file of the current build, from Vite's manifest (entries, their CSS and chunks). */
async function currentBuildFiles() {
    const response = await fetch('/build/manifest.json', { cache: 'no-store' });

    if (!response.ok) {
        return null;
    }

    const manifest = await response.json();
    const files = new Set();

    for (const entry of Object.values(manifest)) {
        if (entry && typeof entry.file === 'string') {
            files.add('/build/' + entry.file);
        }

        for (const css of (entry && entry.css) || []) {
            files.add('/build/' + css);
        }
    }

    return [...files].filter((path) => path.startsWith(BUILD_PREFIX));
}

/**
 * Keep the device's copy of the build current: fetch what is missing, forget what a deploy has
 * replaced. Runs on install and at most every 10 minutes after a page is opened.
 */
async function refreshAssets() {
    lastAssetRefresh = Date.now();

    try {
        const files = await currentBuildFiles();

        if (files === null) {
            return;
        }

        const cache = await caches.open(ASSETS);
        const wanted = new Set(files.map((path) => new URL(path, self.location.origin).href));

        await Promise.allSettled(
            files.map(async (path) => {
                if (!(await cache.match(path))) {
                    const response = await fetch(path);

                    if (response.ok) {
                        await cache.put(path, response);
                    }
                }
            }),
        );

        for (const request of await cache.keys()) {
            if (!wanted.has(request.url)) {
                await cache.delete(request);
            }
        }
    } catch (error) {
        /* Offline or mid-deploy: the next refresh tries again. */
    }
}

async function cacheFirst(cacheName, request) {
    const cache = await caches.open(cacheName);
    const hit = await cache.match(request);

    if (hit) {
        return hit;
    }

    const response = await fetch(request);

    if (response.ok) {
        await cache.put(request, response.clone());
    }

    return response;
}

async function staleWhileRevalidate(cacheName, request) {
    const cache = await caches.open(cacheName);
    const hit = await cache.match(request);
    const network = fetch(request)
        .then(async (response) => {
            if (response.ok || response.type === 'opaque') {
                await cache.put(request, response.clone());
            }

            return response;
        })
        .catch(() => hit);

    return hit || network;
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => refreshAssets())
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    const keep = [CACHE, ASSETS, FONTS];

    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => !keep.includes(key)).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // The app's hashed code and styles: from the device, instantly.
    if (url.origin === self.location.origin && url.pathname.startsWith(BUILD_PREFIX)) {
        event.respondWith(cacheFirst(ASSETS, request));

        return;
    }

    // Fonts: from the device, refreshed in the background.
    if (url.hostname === 'fonts.bunny.net') {
        event.respondWith(staleWhileRevalidate(FONTS, request));

        return;
    }

    // Everything else that is not a full page (Inertia's XHR visits, API calls, images) goes
    // straight to the network, untouched — data is never cached here.
    if (event.request.mode !== 'navigate') {
        return;
    }

    // A page: always live from the server. The offline page only when the network fails.
    if (Date.now() - lastAssetRefresh > REFRESH_EVERY_MS) {
        event.waitUntil(refreshAssets());
    }

    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
});

/*
 * Telegram's avatar colours. A chat notification shows the sender's round initials as its big
 * picture (client reference, 2026-10-04), drawn here so no photo has to be fetched while the
 * app is closed. The colour follows the name, so one person always gets the same one.
 */
const AVATAR_COLOURS = ['#E17076', '#7BC862', '#E5CA77', '#65AADD', '#A695E7', '#EE7AAE', '#6EC9CB', '#FAA774'];

async function avatarFor(name) {
    try {
        if (typeof OffscreenCanvas === 'undefined' || !name) {
            return null;
        }

        const words = String(name).trim().split(/\s+/).filter(Boolean);
        const initials = (words.slice(0, 2).map((word) => Array.from(word)[0]).join('') || '?').toUpperCase();
        let hash = 0;

        for (const character of String(name)) {
            hash = (hash * 31 + character.codePointAt(0)) >>> 0;
        }

        const size = 192;
        const canvas = new OffscreenCanvas(size, size);
        const context = canvas.getContext('2d');

        context.fillStyle = AVATAR_COLOURS[hash % AVATAR_COLOURS.length];
        context.beginPath();
        context.arc(size / 2, size / 2, size / 2, 0, Math.PI * 2);
        context.fill();
        context.fillStyle = '#FFFFFF';
        context.font = '600 76px sans-serif';
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText(initials, size / 2, size / 2 + 4);

        const bytes = new Uint8Array(await (await canvas.convertToBlob({ type: 'image/png' })).arrayBuffer());
        let binary = '';

        for (let index = 0; index < bytes.length; index += 0x8000) {
            binary += String.fromCharCode.apply(null, bytes.subarray(index, index + 0x8000));
        }

        return 'data:image/png;base64,' + btoa(binary);
    } catch (error) {
        return null;
    }
}

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        (async () => {
            const options = {
                body: data.body || '',
                icon: (await avatarFor(data.sender)) || '/brand/icon-192.png',
                badge: '/brand/badge-96.png',
                timestamp: Date.now(),
                data: { url: data.url || '/' },
            };

            // Several messages from one chat replace each other instead of piling up.
            if (data.tag) {
                options.tag = data.tag;
                options.renotify = true;
            }

            await self.registration.showNotification(data.title || 'goodERP', options);
        })(),
    );
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
