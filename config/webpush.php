<?php

/*
 * Web Push (VAPID). `php artisan push:vapid --write` fills the two keys into .env once;
 * deploy/deploy.sh runs it on every release and it never replaces keys that are already set
 * (new keys would silently cut every phone off until each person turned notifications on again).
 */
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://erp.goodtechies.com')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
