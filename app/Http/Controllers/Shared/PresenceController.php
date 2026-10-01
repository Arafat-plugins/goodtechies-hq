<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Last seen" (12-79). The open app pings this; the stored time moves at most every 30 seconds,
 * so a page left open costs one UPDATE per half-minute, not one per ping. Live online/offline is
 * the `online` presence channel; this is what a reader sees once somebody has left it.
 */
class PresenceController extends Controller
{
    public function heartbeat(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subSeconds(30))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return response()->noContent();
    }
}
