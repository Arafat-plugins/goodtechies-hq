<?php

namespace App\Services;

use App\Models\Device;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AuditEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Pairing a timer extension to a person, and unpairing it (docs/extension-api.md §1–§2).
 *
 * A pairing code lives in the cache for ten minutes and is pulled on its first use, so it is
 * single-use whether the exchange succeeds or is refused. The token it buys carries exactly the
 * three timer abilities and nothing else.
 */
class ExtensionPairingService
{
    public const ABILITIES = ['timer:read-tasks', 'timer:track', 'timer:heartbeat'];

    public const CODE_TTL_MINUTES = 10;

    public const TOKEN_NAME = 'timer-extension';

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly AuditLogger $audit) {}

    public function mintCode(User $user): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        Cache::put("extension_pair:$code", $user->id, now()->addMinutes(self::CODE_TTL_MINUTES));

        return $code;
    }

    /**
     * @return array{token: string, device: Device}|array{refused: true}|null
     */
    public function exchange(string $code, string $deviceName, string $installUuid): ?array
    {
        $userId = Cache::pull("extension_pair:$code");

        if ($userId === null) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        if (Gate::forUser($user)->denies('track', TimeEntry::class)) {
            return ['refused' => true];
        }

        return DB::transaction(function () use ($user, $deviceName, $installUuid): array {
            $user->devices()
                ->active()
                ->where('install_uuid', $installUuid)
                ->get()
                ->each(fn (Device $device) => $this->revoke($device, $user));

            $token = $user->createToken(self::TOKEN_NAME, self::ABILITIES);

            // `devices` is unique on (user_id, install_uuid): pairing the same install again
            // lands on its existing row (migration 2026_11_06_0001) rather than a second one.
            $device = Device::query()->updateOrCreate(
                ['user_id' => $user->id, 'install_uuid' => $installUuid],
                [
                    'kind' => 'extension',
                    'name' => $deviceName,
                    'token_id' => $token->accessToken->getKey(),
                    'paired_at' => now(),
                    'last_seen_at' => null,
                    'revoked_at' => null,
                ],
            );

            $this->audit->record(
                AuditEvent::ExtensionPaired,
                $device,
                null,
                ['name' => $device->name, 'install_uuid' => $installUuid],
                $user,
            );

            return ['token' => $token->plainTextToken, 'device' => $device];
        });
    }

    public function revoke(Device $device, ?User $actor = null): void
    {
        DB::transaction(function () use ($device, $actor): void {
            if ($device->token_id !== null) {
                PersonalAccessToken::query()->whereKey($device->token_id)->delete();
            }

            $device->forceFill(['token_id' => null, 'revoked_at' => now()])->save();

            $this->audit->record(
                AuditEvent::ExtensionRevoked,
                $device,
                ['name' => $device->name],
                null,
                $actor,
            );
        });
    }

    public function revokeAllFor(User $user, ?User $actor = null): int
    {
        $devices = $user->devices()->active()->get();

        $devices->each(fn (Device $device) => $this->revoke($device, $actor));

        return $devices->count();
    }
}
