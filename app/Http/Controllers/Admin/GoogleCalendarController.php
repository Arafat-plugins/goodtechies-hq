<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Calendar\GoogleOAuth;
use App\Support\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Polish 030: connecting the agency's Google account, so goodERP creates Meet links itself.
 *
 * Admin → Settings → "Connect Google" → Google's consent screen → back to `callback`, which
 * checks the `state` it sent (a one-time value in the session, so nobody can complete somebody
 * else's sign-in) and stores the tokens encrypted. Gated like Settings: `settings.manage`.
 */
class GoogleCalendarController extends Controller
{
    private const STATE_KEY = 'google_oauth_state';

    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly AuditLogger $audit,
    ) {}

    public function connect(Request $request): RedirectResponse
    {
        if (! $this->oauth->configured()) {
            return redirect()->route('admin.settings')->with('error', 'Google is not set up on the server yet: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI are missing from .env.');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($this->oauth->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = (string) $request->session()->pull(self::STATE_KEY, '');
        $state = (string) $request->query('state', '');

        if ($expected === '' || ! hash_equals($expected, $state)) {
            return redirect()->route('admin.settings')->with('error', 'That Google sign-in could not be confirmed. Press Connect Google again.');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect()->route('admin.settings')->with('error', 'Google sign-in was cancelled.');
        }

        try {
            $account = $this->oauth->connect($code, $request->user());
        } catch (RuntimeException $exception) {
            return redirect()->route('admin.settings')->with('error', $exception->getMessage());
        }

        $this->audit->record(AuditEvent::ConfigurationChanged, $account, null, ['google_account' => $account->email ?? 'connected'], $request->user());

        return redirect()->route('admin.settings')->with('success', sprintf(
            'Google connected%s. New meetings now get their Meet link automatically.',
            $account->email ? ' as '.$account->email : '',
        ));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->oauth->disconnect();

        $this->audit->recordFor(AuditEvent::ConfigurationChanged, 'google_account', null, ['google_account' => 'connected'], ['google_account' => 'disconnected'], $request->user());

        return back()->with('success', 'Google disconnected. Meet links are pasted by hand again.');
    }
}
