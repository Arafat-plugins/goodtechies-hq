<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'GoodTechies HQ') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/brand/mark.svg" type="image/svg+xml">
        <link rel="icon" href="/brand/favicon-32.png" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/brand/favicon-180.png">

        {{-- Theme, before first paint. This runs synchronously in <head>, ahead of the
             stylesheet and the bundle, so the document is already `dark` (or not) the
             first time anything is painted and a reload never flashes the wrong theme.
             `hq.theme` is written by resources/js/lib/theme.ts; `system` is the default
             and is resolved here against the OS preference. --}}
        <script>
            (function () {
                try {
                    var stored = window.localStorage.getItem('hq.theme');
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
