<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Saves the person's Light / Dark / System choice on their own account (2026-10-05), so the
 * Android app's two browsers, every phone and every computer open in the same theme.
 *
 * Only ever the signed-in person's own row; there is no user in the URL to point elsewhere.
 * Sent with `fetch` from `lib/theme.ts` — a preference, not a page — so it answers 204.
 */
class ProfileThemeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'theme' => ['required', 'string', 'in:light,dark,system'],
        ]);

        $request->user()->forceFill(['theme' => $data['theme']])->save();

        return response()->noContent();
    }
}
