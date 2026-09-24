<?php

/*
|--------------------------------------------------------------------------
| Broadcasting (Phase 6)
|--------------------------------------------------------------------------
|
| Three connections and no more. The stack is locked (master prompt Part B):
| self-hosted **Reverb** is the real transport, and `log` is the supported
| fallback for a VPS that is not running it — Part B says a 10–15 s poll is
| acceptable in the MVP "if Reverb is troublesome", so `log` is not a stub, it
| is the other half of a switch somebody flips at 2am.
|
| `BROADCAST_CONNECTION=log` is only half of that switch. The front end has to
| stop opening a socket as well, and that is `VITE_REALTIME` — baked into the
| bundle at build time, which is why it is an `.env` key and not a `settings`
| row (Part D §20 says so in as many words). Both halves live in
| `deploy/.env.production.example`, next to each other, with the runbook.
|
| `null` exists for the test suite (`phpunit.xml` pins it) — a test that means
| to assert a broadcast fakes the dispatcher instead of writing to the log.
|
| Pusher, Ably and Mercure are deliberately absent. Every connection in this
| file is one somebody could point production at by editing one line of .env,
| and three of them would send this agency's notifications to a third party.
|
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle options for the app → Reverb HTTP call. The default is fine on one
                // box; a timeout here would only ever hide a Reverb that is not running.
            ],
        ],

        // The fallback. Every broadcast is written to the log channel instead of a socket, so
        // a payload can still be read back off disk when somebody is working out why the bell
        // is quiet — and the bell itself is polling, because VITE_REALTIME said so at build
        // time. See resources/js/echo.ts.
        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
