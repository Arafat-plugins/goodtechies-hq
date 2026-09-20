<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\LoginHistory;
use App\Services\SessionService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The profile page is shared by every role; the client picks the shell from auth.user.surface.
 */
class ProfileController extends Controller
{
    private const LOGIN_HISTORY_LIMIT = 20;

    public function show(Request $request, SessionService $sessions): Response
    {
        $user = $request->user();

        return Inertia::render('Shared/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'twoFactor' => [
                'enabled' => $user->hasConfirmedTwoFactor(),
                'required' => $user->requiresTwoFactor(),
                'recoveryCodesLeft' => $user->hasConfirmedTwoFactor()
                    ? count($user->two_factor_recovery_codes ?? [])
                    : 0,
            ],
            'sessions' => $sessions->forUser($user, $request->session()->getId())
                ->map(fn (array $session): array => [
                    'id' => $session['id'],
                    'ipAddress' => $session['ip_address'],
                    'userAgent' => $session['user_agent'],
                    'lastActiveAt' => $session['last_active_at']->toIso8601String(),
                    'isCurrent' => $session['is_current'],
                ])
                ->all(),
            'loginHistory' => LoginHistory::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::LOGIN_HISTORY_LIMIT)
                ->get()
                ->map(fn (LoginHistory $row): array => [
                    'succeeded' => $row->succeeded,
                    'ip' => $row->ip,
                    'userAgent' => $row->user_agent,
                    'at' => $row->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        return redirect()->route('profile.show')->with('success', 'Profile updated.');
    }
}
