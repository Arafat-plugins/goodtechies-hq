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

        if ($user->hasConfirmedTwoFactor()) {
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
