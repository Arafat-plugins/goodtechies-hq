<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\BuildsTimerState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Extension\ExchangeCodeRequest;
use App\Models\Device;
use App\Services\ExtensionPairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Pairing and unpairing the timer extension (docs/extension-api.md §1–§2).
 */
class ExtensionPairingController extends Controller
{
    use BuildsTimerState;

    public function __construct(private readonly ExtensionPairingService $pairing) {}

    public function exchange(ExchangeCodeRequest $request): JsonResponse
    {
        $result = $this->pairing->exchange(
            (string) $request->validated('code'),
            (string) $request->validated('device_name'),
            (string) $request->validated('install_uuid'),
        );

        if ($result === null) {
            return response()->json([
                'error' => 'code_invalid',
                'message' => 'That code is not valid any more. Get a new one from your Profile.',
            ], 422);
        }

        if (isset($result['refused'])) {
            return response()->json([
                'error' => 'role_not_allowed',
                'message' => 'Only remote timer users can use the timer extension.',
            ], 403);
        }

        /** @var Device $device */
        $device = $result['device'];
        $user = $device->user;
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'token' => $result['token'],
            'device' => ['id' => (int) $device->id, 'name' => (string) $device->name],
            'user' => ['name' => (string) $user->name],
            'server_time' => Carbon::now()->toIso8601String(),
            'state' => $this->timerState($request, $user->employee),
        ], 201);
    }

    public function disconnect(Request $request): Response|JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        $device = $token instanceof PersonalAccessToken
            ? Device::query()->active()->where('token_id', $token->getKey())->first()
            : null;

        if ($device === null) {
            return response()->json([
                'error' => 'device_not_found',
                'message' => 'This connection is not a paired timer extension.',
            ], 404);
        }

        $this->pairing->revoke($device, $request->user());

        return response()->noContent();
    }
}
