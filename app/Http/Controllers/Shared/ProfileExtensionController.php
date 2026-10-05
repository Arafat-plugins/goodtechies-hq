<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\TimeEntry;
use App\Services\ExtensionPairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Profile → "Connect timer extension" (docs/extension-api.md §2): mint a pairing code, and
 * disconnect a paired extension. Remote timer users only.
 */
class ProfileExtensionController extends Controller
{
    public function __construct(private readonly ExtensionPairingService $pairing) {}

    public function code(Request $request): JsonResponse
    {
        Gate::authorize('track', TimeEntry::class);

        $code = $this->pairing->mintCode($request->user());

        return response()->json([
            'code' => $code,
            'expires_in_minutes' => ExtensionPairingService::CODE_TTL_MINUTES,
        ]);
    }

    /**
     * Somebody else's device is 404, never a refusal that would confirm it exists.
     */
    public function destroyDevice(Request $request, Device $device): RedirectResponse
    {
        if ((int) $device->user_id !== (int) $request->user()->id) {
            throw new NotFoundHttpException;
        }

        $this->pairing->revoke($device, $request->user());

        return back()->with('success', 'Timer extension disconnected.');
    }
}
