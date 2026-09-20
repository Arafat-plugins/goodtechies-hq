<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ConfirmPasswordRequest;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use LogicException;

class ProfileTwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Admins and Accountants cannot turn 2FA off.
     */
    public function destroy(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        try {
            $this->twoFactor->disable($user, $user);
        } catch (LogicException) {
            abort(403, 'Two-factor authentication is required for your role.');
        }

        return redirect()->route('profile.show')->with('success', 'Two-factor authentication disabled.');
    }

    public function regenerateRecoveryCodes(ConfirmPasswordRequest $request): RedirectResponse
    {
        try {
            $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());
        } catch (LogicException) {
            return redirect()->route('profile.show')->with('error', 'Two-factor authentication is not enabled.');
        }

        $request->session()->flash('recoveryCodes', $codes);

        return redirect()->route('two-factor.recovery-codes');
    }
}
