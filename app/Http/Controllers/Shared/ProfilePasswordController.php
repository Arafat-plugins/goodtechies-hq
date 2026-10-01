<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Services\PushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProfilePasswordController extends Controller
{
    /**
     * Change the password and sign out every other device.
     */
    public function __invoke(UpdatePasswordRequest $request, PushService $push): RedirectResponse
    {
        $user = $request->user();

        // A new remember token stops "remember me" cookies on other devices from signing back in.
        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        // A lost or shared device stops showing this person's notifications.
        $push->forgetAllDevices($user);

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return redirect()->route('profile.show')->with('success', 'Password updated. Other devices have been signed out.');
    }
}
