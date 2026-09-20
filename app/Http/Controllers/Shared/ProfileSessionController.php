<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Services\SessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProfileSessionController extends Controller
{
    /**
     * End one of the user's other sessions. An unknown or foreign id is a 404 (thrown by the service).
     */
    public function destroy(Request $request, SessionService $sessions, string $session): RedirectResponse
    {
        $user = $request->user();

        try {
            $sessions->revoke($user, $user, $session, $request->session()->getId());
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['session' => $e->getMessage()]);
        }

        return redirect()->route('profile.show')->with('success', 'Session ended.');
    }
}
