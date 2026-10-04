<?php

/*
 * The Android app is a Trusted Web Activity over the live site (android/README.md). It only
 * opens full-screen while these public files exist and agree with the APK, so a later change
 * that drops one of them fails here instead of on a phone.
 */

function PWA_path(string $relative): string
{
    return dirname(__DIR__, 2).'/'.$relative;
}

it('links the web app manifest from the root view', function () {
    $view = file_get_contents(PWA_path('resources/views/app.blade.php'));

    expect($view)->toContain('<link rel="manifest" href="/manifest.json">');
});

it('ships a manifest Chrome accepts as installable', function () {
    $manifest = json_decode(file_get_contents(PWA_path('public/manifest.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['start_url'])->toBe('/')
        ->and($manifest['display'])->toBe('standalone')
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512');

    foreach ($manifest['icons'] as $icon) {
        expect(file_exists(PWA_path('public'.$icon['src'])))->toBeTrue();
    }
});

it('registers a service worker that never caches app pages', function () {
    $worker = file_get_contents(PWA_path('public/sw.js'));

    expect(file_exists(PWA_path('public/offline.html')))->toBeTrue()
        ->and($worker)->toContain("event.request.mode !== 'navigate'")
        // Speed (2026-10-04): the hashed build files come from the device; pages never do.
        ->and($worker)->toContain("const BUILD_PREFIX = '/build/assets/';")
        ->and($worker)->toContain('event.respondWith(cacheFirst(ASSETS, request));')
        ->and($worker)->toContain('event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));')
        ->and(file_get_contents(PWA_path('resources/js/app.ts')))->toContain("navigator.serviceWorker.register('/sw.js')");
});

it('publishes the digital asset links for the Android package', function () {
    $links = json_decode(file_get_contents(PWA_path('public/.well-known/assetlinks.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($links[0]['target']['package_name'])->toBe('com.goodtechies.erp')
        ->and($links[0]['target']['sha256_cert_fingerprints'])->toHaveCount(1);
});
