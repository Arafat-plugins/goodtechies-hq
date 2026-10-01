<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Creates the VAPID key pair push notifications are signed with. With --write it fills them
 * into .env once and never replaces keys that are already there (deploy/deploy.sh runs it on
 * every release).
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid {--write : Write the keys into .env when it has none yet}';

    protected $description = 'Create the VAPID key pair for push notifications';

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();

        if (! is_file($path)) {
            $this->error('No .env file at '.$path.' — create it first.');

            return self::FAILURE;
        }

        $env = (string) file_get_contents($path);

        $hasPublic = preg_match('/^VAPID_PUBLIC_KEY=(?!""|\'\'|\s*$)\S+/m', $env) === 1;
        $hasPrivate = preg_match('/^VAPID_PRIVATE_KEY=(?!""|\'\'|\s*$)\S+/m', $env) === 1;

        if ($hasPublic && $hasPrivate) {
            $this->info('VAPID keys are already set — leaving them (new keys would cut every device off).');

            return self::SUCCESS;
        }

        if ($hasPublic || $hasPrivate) {
            $this->error('Only one VAPID key is set in .env — set both by hand (never replace a live public key).');

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();

        if (! $this->option('write')) {
            $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
            $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

            return self::SUCCESS;
        }

        foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']] as $key => $value) {
            $pattern = '/^'.$key.'=.*$/m';

            if (preg_match($pattern, $env)) {
                $env = (string) preg_replace($pattern, $key.'='.$value, $env);
            } else {
                $env .= "\n".$key.'='.$value;
            }
        }

        // Atomic, and keeps the file's permissions.
        $tmp = $path.'.vapid.tmp';
        file_put_contents($tmp, $env);
        @chmod($tmp, fileperms($path) & 0777);
        rename($tmp, $path);

        $this->info('VAPID keys written to .env.');

        return self::SUCCESS;
    }
}
