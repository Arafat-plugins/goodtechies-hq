<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /**
     * Checked against when the address is unknown, so the reply costs the same time either way
     * and does not disclose which addresses exist. Bcrypt of a random value nobody holds.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$K6rvPTM/XfY0KBBTgf.8jeEc9f15sBJZSTBNEmRByAiPWLxe8pC8q';

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Password step. Users with confirmed 2FA stay guests until the challenge is passed.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $email = mb_strtolower((string) $request->validated('email'));
        $password = (string) $request->validated('password');
        $remember = $request->boolean('remember');

        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            // Hash anyway, so an unknown address does not answer faster than a known one.
            Hash::check($password, self::DUMMY_PASSWORD_HASH);
        }

        if ($user === null || ! Hash::check($password, $user->password)) {
            // The Failed listener writes the login_history row; the password is never passed on.
            event(new Failed('web', $user, ['email' => $request->validated('email')]));

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->isActive()) {
            event(new Failed('web', $user, ['email' => $request->validated('email')]));

            throw ValidationException::withMessages([
                'email' => 'This account is inactive.',
            ]);
        }

        // TwoFactorService::isEnforced() is the single predicate behind the local-development
        // switch; it is true in production regardless of AUTH_TWO_FACTOR_ENFORCED. When it is
        // false the confirmed secret is left exactly as it is, just not asked for.
        if (TwoFactorService::isEnforced() && $user->hasConfirmedTwoFactor()) {
            // A fresh id before the pending keys are written, so the pre-auth session that
            // carried the password step is not the one the challenge runs on.
            $request->session()->regenerate();

            $request->session()->put(TwoFactorService::PENDING_LOGIN_SESSION_KEY, $user->id);
            $request->session()->put('login.remember', $remember);

            return redirect()->route('two-factor.challenge');
        }

        $request->session()->forget([TwoFactorService::PENDING_LOGIN_SESSION_KEY, 'login.remember']);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
