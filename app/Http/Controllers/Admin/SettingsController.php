<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only in Phase 0: the settings list, the backup check and the configured drivers.
 */
class SettingsController extends Controller
{
    public function __invoke(SettingsService $settings): Response
    {
        $values = $settings->all();

        $list = [];

        foreach ($values as $key => $value) {
            $list[] = ['key' => $key, 'value' => $value];
        }

        $lastVerifiedAt = $values['backup_last_verified_at'] ?? null;

        return Inertia::render('Admin/Settings', [
            'settings' => $list,
            'backup' => [
                'lastVerifiedAt' => is_string($lastVerifiedAt) ? $lastVerifiedAt : null,
            ],
            'drivers' => [
                'realtime' => config('broadcasting.default'),
                'googleCalendar' => config('services.google_calendar.driver', 'manual'),
            ],
        ]);
    }
}
