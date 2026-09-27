<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Calendar (Phase 7, master prompt Part D §12 and §20)
    |--------------------------------------------------------------------------
    |
    | Which calendar driver a deployment runs. `manual` is the default and Part D says it is
    | *"always available"*: the organiser opens Google's instant-meeting flow and pastes the
    | link back, and the application needs no Google credentials at all.
    |
    | This is `.env` and **not** a `settings` key, deliberately. Part D §20 closes the settings
    | list and names this as one of the two things that live in `.env` precisely because they
    | cannot switch at runtime — swapping drivers changes which classes are bound at boot and
    | which credentials must be present. Admin → Settings shows it read-only.
    |
    | `api` is the second driver Part D §12 describes and is **not built**: it needs a Google
    | Workspace decision the client has not made (PROGRESS.md GATE A question 3 — per-organiser
    | OAuth, or a service account with domain-wide delegation; a plain service account cannot
    | create Meet links). AppServiceProvider refuses to boot with an unknown value rather than
    | quietly falling back to `manual`, so a deployment that sets this to `api` today finds out
    | immediately instead of running for a week with no Meet links and nobody noticing.
    |
    */

    'google_calendar' => [
        'driver' => env('GOOGLE_CALENDAR_DRIVER', 'manual'),
    ],

];
