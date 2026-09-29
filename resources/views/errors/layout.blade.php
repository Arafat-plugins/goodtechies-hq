{{--
    Reliability slice 2a: the full-page (non-Inertia) error pages — a typed URL, a refresh on an
    error, an opened bookmark. Same titles as `Pages/Shared/Error.vue`, which is what an Inertia
    visit gets instead. Plain HTML on the app's own stylesheet: no script (the Content-Security-
    Policy allows exactly one hashed inline script, the theme one in app.blade.php), so "Go back"
    is the previous URL the server knows rather than `history.back()`.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') — {{ config('app.name', 'goodERP') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/brand/mark.svg" type="image/svg+xml">
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center bg-muted p-4 md:p-6">
            <main class="flex w-full max-w-sm flex-col gap-6">
                <div class="flex flex-col items-center gap-4 rounded-xl border bg-card p-6 text-center text-card-foreground shadow-raised">
                    <div class="flex flex-col gap-1">
                        <h1 class="text-base font-semibold text-foreground">@yield('title')</h1>
                        <p class="text-sm text-muted-foreground tabular-nums">Error @yield('code')</p>
                    </div>
                    <div class="flex w-full flex-col gap-2">
                        <a href="{{ url()->previous() }}" class="inline-flex h-9 items-center justify-center rounded-md border bg-background px-4 text-sm font-medium text-foreground shadow-flat outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring">Go back</a>
                        <a href="{{ url('/') }}" class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-flat outline-none hover:bg-primary/90 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring">Go to my home page</a>
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
