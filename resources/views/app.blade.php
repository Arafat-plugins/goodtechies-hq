<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"@if (in_array(auth()->user()?->theme, ['light', 'dark', 'system'], true)) data-theme="{{ auth()->user()->theme }}"@endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'goodERP') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/brand/mark.svg" type="image/svg+xml">
        <link rel="icon" href="/brand/favicon-32.png" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/brand/favicon-180.png">
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#FCFDFE" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0A0D12" media="(prefers-color-scheme: dark)">
        {{-- Polish 016: the public VAPID key, for the "get a popup" card on every screen. Public by design. --}}
        <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">

        {{-- Theme, before first paint. This runs synchronously in <head>, ahead of the
             stylesheet and the bundle, so the document is already `dark` (or not) the
             first time anything is painted and a reload never flashes the wrong theme.
             The account's choice (`data-theme` on <html>, 2026-10-05) wins, so Chrome and
             the Android app's own WebView agree; otherwise `hq.theme`, written by
             resources/js/lib/theme.ts. `system` is the default and is resolved here against
             the OS preference. The script is the same bytes for everybody: its CSP hash
             (ContentSecurityPolicy::THEME_SCRIPT_HASH) depends on that. --}}
        <script>
            (function () {
                try {
                    var stored = document.documentElement.getAttribute('data-theme')
                        || window.localStorage.getItem('hq.theme');
                    var mode = stored === 'light' || stored === 'dark' ? stored : 'system';
                    var dark = mode === 'dark' || (mode === 'system'
                        && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {
                    /* Blocked storage: the default theme is the light one already on <html>. */
                }
            })();
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
