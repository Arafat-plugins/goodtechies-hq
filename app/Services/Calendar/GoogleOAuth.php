<?php

namespace App\Services\Calendar;

use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Polish 030: signing goodERP in to the agency's Google account, and keeping a fresh access
 * token for the Calendar API.
 *
 * Plain OAuth 2.0 over Laravel's HTTP client — no SDK. Scope `calendar.events` only: goodERP
 * can create, move and delete events on that calendar and nothing else of the account.
 */
class GoogleOAuth
{
    public const SCOPES = 'https://www.googleapis.com/auth/calendar.events openid email';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** Refresh this long before Google's expiry, so a token never dies mid-call. */
    private const EARLY_SECONDS = 120;

    public function configured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirect() !== '';
    }

    public function connected(): bool
    {
        return $this->configured() && GoogleAccount::current() !== null;
    }

    /** Where "Connect Google" sends the Admin. `$state` is checked on the way back. */
    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirect(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            // `offline` + `consent` is what makes Google hand over a refresh token every time.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Swap the code Google returned for tokens and store them (replacing any earlier account).
     *
     * @throws RuntimeException
     */
    public function connect(string $code, User $actor): GoogleAccount
    {
        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirect(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || ! is_string($response->json('refresh_token'))) {
            throw new RuntimeException('Google did not return a refresh token. Try connecting again.');
        }

        $email = null;
        $access = (string) $response->json('access_token');

        $profile = Http::withToken($access)->timeout(10)->get(self::USERINFO_URL);

        if ($profile->successful() && is_string($profile->json('email'))) {
            $email = $profile->json('email');
        }

        GoogleAccount::query()->delete();

        return GoogleAccount::query()->create([
            'email' => $email,
            'refresh_token' => (string) $response->json('refresh_token'),
            'access_token' => $access,
            'access_token_expires_at' => Carbon::now()->addSeconds((int) $response->json('expires_in', 3600)),
            'connected_by' => $actor->getKey(),
        ]);
    }

    public function disconnect(): void
    {
        $account = GoogleAccount::current();

        if ($account !== null) {
            // Best effort: tell Google to forget the grant; the row goes either way.
            rescue(fn () => Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', [
                'token' => $account->refresh_token,
            ]), report: false);
        }

        GoogleAccount::query()->delete();
    }

    /**
     * A live access token, refreshed when it is about to expire.
     *
     * @throws RuntimeException
     */
    public function accessToken(): string
    {
        $account = GoogleAccount::current();

        if ($account === null) {
            throw new RuntimeException('No Google account is connected.');
        }

        $expires = $account->access_token_expires_at;

        if ($account->access_token !== null && $expires !== null && $expires->isAfter(now()->addSeconds(self::EARLY_SECONDS))) {
            return $account->access_token;
        }

        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Google refused to refresh the access token. Reconnect Google in Settings.');
        }

        $account->forceFill([
            'access_token' => (string) $response->json('access_token'),
            'access_token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ])->save();

        return (string) $account->access_token;
    }

    private function clientId(): string
    {
        return trim((string) config('services.google_calendar.client_id'));
    }

    private function clientSecret(): string
    {
        return trim((string) config('services.google_calendar.client_secret'));
    }

    private function redirect(): string
    {
        return trim((string) config('services.google_calendar.redirect'));
    }
}
