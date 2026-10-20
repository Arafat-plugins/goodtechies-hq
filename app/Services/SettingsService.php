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
    public const READ_ONLY = ['backup_last_verified_at', 'usd_bdt_rate_updated_at'];

    /**
     * Keys an admin edits on a screen of their own rather than Admin → Settings (polish 026:
     * the project type names, renamed beside the Type box on the project form; polish 033: the
     * service boxes, edited on Admin → Projects; D7: the USD→BDT rate and its mode, edited on the
     * finance report screen).
     */
    public const OWN_SCREEN = ['project_type_labels', 'project_service_boxes', 'usd_bdt_rate', 'usd_bdt_rate_mode'];

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
    public function set(string $key, mixed $value, User $actor, Permission $permission = Permission::SettingsManage): void
    {
        $this->assertKnown($key);

        if (in_array($key, self::READ_ONLY, true)) {
            throw UnknownSettingException::readOnly($key);
        }

        // `$permission` lets a screen of its own ask its own key (D7: the exchange rate is
        // changed by whoever holds finance.manage); it defaults to settings.manage.
        if (! $actor->isActive() || ! $actor->hasPermission($permission)) {
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

    /**
     * Written by the system (the exchange-rate refresh): no actor and no audit row, and it may
     * write a READ_ONLY key.
     */
    public function setSystem(string $key, mixed $value): void
    {
        $this->assertKnown($key);

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        $this->values = null;
    }

    private function assertKnown(string $key): void
    {
        if (! array_key_exists($key, SettingsSeeder::DEFAULTS)) {
            throw UnknownSettingException::unknown($key);
        }
    }
}
