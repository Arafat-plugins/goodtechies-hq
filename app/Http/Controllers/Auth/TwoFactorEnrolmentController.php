<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorConfirmRequest;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enrolment is forced for Admin and Accountant (the `two-factor` middleware) and optional for others.
 * The secret and the recovery codes reach only these two pages.
 */
class TwoFactorEnrolmentController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasConfirmedTwoFactor()) {
            return redirect()->route('home');
        }

        $secret = $user->two_factor_secret ?? $this->twoFactor->beginEnrolment($user);

        return Inertia::render('Auth/TwoFactorEnrol', [
            'qrSvg' => $this->twoFactor->qrCodeSvg($user, $secret),
            'secret' => $secret,
            'required' => $user->requiresTwoFactor(),
        ]);
    }

    public function store(TwoFactorConfirmRequest $request): RedirectResponse
    {
        $codes = $this->twoFactor->confirmEnrolment($request->user(), (string) $request->validated('code'));

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'The code is invalid.']);
        }

        $request->session()->flash('recoveryCodes', $codes);

        return redirect()->route('two-factor.recovery-codes');
    }

    /**
     * Shows freshly issued recovery codes once; they exist only in the flash.
     */
    public function recoveryCodes(Request $request): Response|RedirectResponse
    {
        $codes = $request->session()->get('recoveryCodes');

        if (! is_array($codes) || $codes === []) {
            return redirect()->route('home');
        }

        return Inertia::render('Auth/RecoveryCodes', [
            'codes' => array_values($codes),
        ]);
    }
}
