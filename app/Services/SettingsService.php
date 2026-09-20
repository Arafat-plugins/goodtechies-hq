<?php

namespace App\Services;

use App\Exceptions\UnknownSettingException;
use App\Models\Setting;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\Permission;
use Carbon\CarbonInterface;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the fixed list of settings keys (SettingsSeeder::DEFAULTS).
 * Bound as scoped, so values are cached for one request or job.
 */
class SettingsService
{
    /**
     * Keys only the system writes; admins cannot change them.
     */
    public const READ_ONLY = ['backup_last_verified_at'];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $values = null;

    public function __construct(private readonly AuditLogger $audit) {}

    public function get(string $key): mixed
    {
        $this->assertKnown($key);

        return $this->all()[$key];
    }

    /**
     * Every setting in DEFAULTS order, with the stored value (or the default when no row exists).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->values === null) {
            $stored = Setting::query()
                ->whereIn('key', array_keys(SettingsSeeder::DEFAULTS))
                ->pluck('value', 'key')
                ->all();

            $this->values = [];

            foreach (SettingsSeeder::DEFAULTS as $key => $default) {
                $this->values[$key] = array_key_exists($key, $stored) ? $stored[$key] : $default;
            }
        }

        return $this->values;
    }

    /**
     * @throws UnknownSettingException
     * @throws AuthorizationException
     */
    public function set(string $key, mixed $value, User $actor): void
    {
        $this->assertKnown($key);

        if (in_array($key, self::READ_ONLY, true)) {
            throw UnknownSettingException::readOnly($key);
        }

        if (! $actor->isActive() || ! $actor->hasPermission(Permission::SettingsManage)) {
            throw new AuthorizationException('You are not allowed to change settings.');
        }

        $old = $this->get($key);

        if ($old === $value) {
            return;
        }

        DB::transaction(function () use ($key, $old, $value, $actor): void {
            $setting = Setting::query()->firstOrNew(['key' => $key]);
            $setting->value = $value;
            $setting->save();

            $this->audit->recordFor(
                AuditEvent::ConfigurationChanged,
                'setting',
                $setting->id,
                [$key => $old],
                [$key => $value],
                $actor,
            );
        });

        $this->values = null;
    }

    /**
     * Written by the backup verification job: no actor and no audit row.
     */
    public function recordBackupVerified(CarbonInterface $at): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'backup_last_verified_at'],
            ['value' => $at->toIso8601String()],
        );

        $this->values = null;
    }

    private function assertKnown(string $key): void
    {
        if (! array_key_exists($key, SettingsSeeder::DEFAULTS)) {
            throw UnknownSettingException::unknown($key);
        }
    }
}
