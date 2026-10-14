<?php

/*
 * Prints the LIVE server's VITE_* values, resolved, one KEY=value per line.
 *
 * deploy-live.bat sends this file over ssh to `php` on the server and builds the assets on the
 * PC with exactly these values, because `npm run build` bakes VITE_* into the JavaScript and the
 * PC's own .env holds local ones (a local Reverb host, say). Read-only: it prints and nothing else.
 */

chdir('/var/www/goodtechies-hq');

require 'vendor/autoload.php';

$values = Dotenv\Dotenv::createArrayBacked(getcwd())->load();

foreach ($values as $key => $value) {
    if (str_starts_with((string) $key, 'VITE_')) {
        echo $key, '=', str_replace(["\r", "\n"], '', (string) $value), "\n";
    }
}
