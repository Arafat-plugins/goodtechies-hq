<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * The one reply for every address, known or not, so the form discloses nothing about which
     * accounts exist.
     */
    public const STATUS = 'If that email belongs to an active goodERP account, a reset link is on its way. It works for 60 minutes. Check your spam folder too.';

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(SendPasswordResetLinkRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('email');

        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null && $user->isActive()) {
            // The broker looks the account up by exact address, so it is given the stored one.
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', self::STATUS);
    }
}
