<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(TwoFactorChallengeRequest $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null || ! $user->isActive()) {
            $this->forgetPendingLogin($request);

            return redirect()->route('login');
        }

        $code = (string) $request->validated('code');

        $passed = $code !== ''
            ? $this->twoFactor->verifyCode($user, $code)
            : $this->twoFactor->useRecoveryCode($user, (string) $request->validated('recovery_code'));

        if (! $passed) {
            throw ValidationException::withMessages(['code' => 'The code is invalid.']);
        }

        $remember = (bool) $request->session()->get('login.remember', false);

        $this->forgetPendingLogin($request);

        Auth::loginUsingId($user->id, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(TwoFactorService::PENDING_LOGIN_SESSION_KEY);

        return $id === null ? null : User::find($id);
    }

    private function forgetPendingLogin(Request $request): void
    {
        $request->session()->forget([TwoFactorService::PENDING_LOGIN_SESSION_KEY, 'login.remember']);
    }
}
