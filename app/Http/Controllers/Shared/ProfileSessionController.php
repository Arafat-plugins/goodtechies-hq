<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Services\PushService;
use App\Services\SessionService;
use App\Support\RememberCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProfileSessionController extends Controller
{
    /**
     * End one of the user's other sessions. An unknown or foreign id is a 404 (thrown by the service).
     */
    public function destroy(Request $request, SessionService $sessions, PushService $push, string $session): RedirectResponse
    {
        $user = $request->user();

        try {
            $sessions->revoke($user, $user, $session, $request->session()->getId());

            // A lost or shared device stops showing this person's notifications.
            $push->forgetAllDevices($user);

            // ...and cannot sign straight back in with its 400-day "remember me" cookie (12-89).
            // This device keeps its own login.
            RememberCookie::cycleKeepingThisDevice($user);
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['session' => $e->getMessage()]);
        }

        return redirect()->route('profile.show')->with('success', 'Session ended.');
    }
}
