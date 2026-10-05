<?php

namespace App\Services;

use App\Models\AppPushToken;
use App\Models\User;
use App\Support\PushCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push to the goodERP Android app itself, through Firebase Cloud Messaging (2026-10-05).
 *
 * Web Push only reaches the app while it runs through Chrome; in its WebView backup screen there
 * is no Web Push at all, so nothing reached the phone's notification shade. Each installed app
 * now has a Firebase token (`app_push_tokens`), and this sends to it with the FCM HTTP v1 API.
 *
 * Credentials: a Firebase service-account JSON key on the server, path in `FCM_CREDENTIALS`
 * (`config('services.fcm.credentials')`). It never goes in the repository. Without it this is
 * switched off and sends nothing — Web Push carries on exactly as before.
 *
 * Data-only messages: the app draws the notification itself (title, text, which chat to open),
 * so it looks the same whether the app is open, in the background or closed.
 */
class AppPushService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var array{project_id: string, client_email: string, private_key: string, token_uri: string}|null|false */
    private array|null|false $credentials = false;

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    public function hasDevices(User $user): bool
    {
        return AppPushToken::query()->where('user_id', $user->getKey())->exists();
    }

    public function forgetAllDevices(User $user): int
    {
        return AppPushToken::query()->where('user_id', $user->getKey())->delete();
    }

    /**
     * @param  array<string, mixed>  $message  title, body, url, tag, sender — what Web Push sends
     * @return int  how many of this person's phones accepted it
     */
    public function send(User $user, PushCategory $category, array $message): int
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return 0;
        }

        $tokens = AppPushToken::query()->where('user_id', $user->getKey())->get();

        if ($tokens->isEmpty()) {
            return 0;
        }

        try {
            $accessToken = $this->accessToken($credentials);
        } catch (Throwable $e) {
            Log::warning('App push: no Firebase access token', ['reason' => $e->getMessage()]);

            return 0;
        }

        // FCM data values must all be strings.
        $data = collect($message + ['category' => $category->value])
            ->filter(fn ($value) => is_scalar($value) && $value !== '')
            ->map(fn ($value) => (string) $value)
            ->all();

        $url = 'https://fcm.googleapis.com/v1/projects/'.rawurlencode($credentials['project_id']).'/messages:send';
        $sent = 0;

        foreach ($tokens as $token) {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->post($url, [
                    'message' => [
                        'token' => $token->token,
                        'data' => $data,
                        'android' => ['priority' => 'HIGH', 'ttl' => '86400s'],
                    ],
                ]);

            if ($response->successful()) {
                $sent++;

                continue;
            }

            // The app was uninstalled, or its token was replaced: forget it.
            $status = (string) $response->json('error.status', '');
            $code = (string) collect($response->json('error.details', []))->pluck('errorCode')->filter()->first();

            if ($response->status() === 404 || $status === 'NOT_FOUND' || $code === 'UNREGISTERED'
                || ($response->status() === 400 && $code === 'INVALID_ARGUMENT')) {
                $token->delete();

                continue;
            }

            Log::warning('App push was not delivered', [
                'user_id' => $user->getKey(),
                'status' => $response->status(),
                'reason' => $status !== '' ? $status : $code,
            ]);
        }

        return $sent;
    }

    /**
     * An OAuth access token for FCM from the service account (a signed JWT exchanged at Google),
     * cached a little under its hour.
     *
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri: string}  $credentials
     */
    protected function accessToken(array $credentials): string
    {
        return Cache::remember('app-push:access-token:'.md5($credentials['client_email']), 3300, function () use ($credentials): string {
            $now = time();
            $segments = [
                self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
                self::base64url(json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => $credentials['token_uri'],
                    'iat' => $now,
                    'exp' => $now + 3600,
                ], JSON_THROW_ON_ERROR)),
            ];

            $signature = '';

            if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('The Firebase private key could not sign.');
            }

            $segments[] = self::base64url($signature);

            $token = Http::asForm()
                ->timeout(10)
                ->post($credentials['token_uri'], [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => implode('.', $segments),
                ])
                ->throw()
                ->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('Google answered without an access token.');
            }

            return $token;
        });
    }

    /**
     * The service-account key, or null when push to the app is not set up.
     *
     * @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null
     */
    protected function credentials(): ?array
    {
        if ($this->credentials !== false) {
            return $this->credentials;
        }

        $path = (string) config('services.fcm.credentials', '');
        $json = $path !== '' && is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        $this->credentials = is_array($json)
            && is_string($json['project_id'] ?? null) && $json['project_id'] !== ''
            && is_string($json['client_email'] ?? null) && $json['client_email'] !== ''
            && is_string($json['private_key'] ?? null) && $json['private_key'] !== ''
            ? [
                'project_id' => $json['project_id'],
                'client_email' => $json['client_email'],
                'private_key' => $json['private_key'],
                // Only Google's own token endpoint, whatever the file says.
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ]
            : null;

        return $this->credentials;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
