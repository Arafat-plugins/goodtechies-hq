<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PushService;
use App\Support\AuditEvent;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * Sets the new password, ends every session and rotates the remember token. It does NOT sign
     * the user in: they sign in afresh, so two-factor still happens there.
     */
    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('email');

        // The broker looks the account up by exact address, so it is given the stored one.
        $stored = User::whereRaw('lower(email) = ?', [$email])->value('email');

        $credentials = [
            'email' => $stored ?? $email,
            'password' => (string) $request->validated('password'),
            'password_confirmation' => (string) $request->input('password_confirmation'),
            'token' => (string) $request->validated('token'),
        ];

        $status = Password::broker()->reset($credentials, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                if (! $user->isActive()) {
                    throw ValidationException::withMessages([
                        'email' => 'This reset link is invalid or has expired. Ask for a new one.',
                    ]);
                }

                // The `hashed` cast on User::$password hashes it.
                $user->forceFill(['password' => $password]);
                $user->setRememberToken(Str::random(60));
                $user->save();

                // A lost or shared device stops showing this person's notifications.
                app(PushService::class)->forgetAllDevices($user);

                DB::table('sessions')->where('user_id', $user->id)->delete();

                $this->audit->record(
                    AuditEvent::PasswordResetByEmail,
                    $user,
                    null,
                    ['email' => $user->email, 'sessions_ended' => true],
                    $user,
                );

                event(new PasswordReset($user));
            });
        });

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('status', 'Your password has been changed. Sign in with the new one.');
        }

        return back()
            ->withErrors(['email' => 'This reset link is invalid or has expired. Ask for a new one.'])
            ->withInput($request->only('email'));
    }
}
