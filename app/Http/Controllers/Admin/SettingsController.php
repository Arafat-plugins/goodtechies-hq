<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Settings (master prompt Part E, Phase 12: *"**every key from Part D §20** grouped:
 * General … Attendance … Timer … Notifications … Integrations — Google driver and realtime
 * driver **read-only from `.env`**; Backup health card with `backup_last_verified_at`,
 * read-only"*).
 *
 * ## The write path already existed and had no caller
 *
 * `SettingsService::set()` has been here since Phase 0: it refuses an unknown key, refuses
 * `SettingsService::READ_ONLY`, asks `settings.manage` of the actor, returns early when the
 * value has not changed, and writes the `configuration.changed` audit row with the old and new
 * value inside the same transaction as the setting. Every one of those rules is therefore
 * stated once. `update()` below calls it in a loop and does **not** restate any of them — there
 * is no second writer of `settings` and no second place that decides what may be written.
 *
 * ## The form is generated from the Form Request's own table
 *
 * `UpdateSettingsRequest::FIELDS` carries the group, the type, the range and the help text for
 * every editable key, and `rules()` there is generated from the same table. `index()` sends it
 * to the screen as `fields`, so the control somebody sees, the bounds the server enforces and
 * the sentence under the box are one fact in one place. A range changed in the Form Request
 * changes the `min`/`max` on the number input with no edit here and none in Vue.
 *
 * ## Three things on this page are deliberately not fields
 *
 *   1. **`backup_last_verified_at`** — `READ_ONLY` in the service, absent from `FIELDS`, drawn
 *      as the backup health card. `hq:verify-backup` is its only writer.
 *   2. **The realtime driver** and **3. the Google Calendar driver** — Part D §20 lists both
 *      under *"Not settings, because they cannot switch at runtime"*. They are read from config
 *      with the `.env` key and the reason beside them, because a read-only row that does not
 *      say *why* it is read-only reads as an unfinished feature; see `drivers` below.
 *
 * `settings` is still the flat key/value list Phase 0 sent, unchanged: it is the values the
 * form is populated from, and `tests/Feature/Surfaces/ShellLandingTest` pins its shape.
 */
class SettingsController extends Controller
{
    public function index(SettingsService $settings): Response
    {
        $values = $settings->all();

        $list = [];

        foreach ($values as $key => $value) {
            $list[] = ['key' => $key, 'value' => $value];
        }

        $lastVerifiedAt = $values['backup_last_verified_at'] ?? null;

        return Inertia::render('Admin/Settings', [
            'settings' => $list,
            // The editable half of Part D §20, described once — see the Form Request.
            'fields' => $this->fields(),
            'sections' => UpdateSettingsRequest::SECTIONS,
            'backup' => [
                'lastVerifiedAt' => is_string($lastVerifiedAt) ? $lastVerifiedAt : null,
            ],
            'drivers' => [
                'realtime' => config('broadcasting.default'),
                'realtimeEnv' => 'BROADCAST_CONNECTION, VITE_REALTIME',
                // Part D §20: the realtime driver is *"baked at build time"*. `VITE_*` values
                // are compiled into the JavaScript bundle by Vite, so a driver changed in the
                // database would leave every browser talking to the transport the last build
                // was made against. Changing it is an `.env` edit, a rebuild and a restart of
                // the Reverb worker, in that order.
                'realtimeWhy' => 'The browser half of this is compiled into the JavaScript bundle, so it cannot change while the app is running. Edit the .env values, rebuild the assets and restart the realtime worker.',
                'googleCalendar' => config('services.google_calendar.driver', 'manual'),
                'googleCalendarEnv' => 'GOOGLE_CALENDAR_DRIVER',
                // Switching to `api` needs OAuth credentials, and Part C §3 keeps secrets in
                // `.env` and never in the database — so the switch has to live where the
                // credentials live, or the two could disagree.
                'googleCalendarWhy' => 'The api driver needs Google credentials, and credentials live in .env rather than in the database. Moving the switch here would let it be turned on without them.',
            ],
        ]);
    }

    /**
     * Save whatever the section that was submitted sent.
     *
     * One `set()` per key, because that is the unit the audit log records: an Admin who changes
     * the grace period and the currency in one save produces two `configuration.changed` rows,
     * each with its own old and new value, which is what makes the Audit Log readable. A key
     * whose value is unchanged writes nothing — `set()` returns early — so re-saving a section
     * does not fill the log with rows that say nothing happened.
     */
    public function update(UpdateSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $actor = $request->user();
        $changes = $request->changes();

        foreach ($changes as $key => $value) {
            $settings->set($key, $value, $actor);
        }

        return back()->with('success', count($changes) === 1
            ? 'Setting saved.'
            : 'Settings saved.');
    }

    /**
     * The field descriptors, as a list in Part D §20's own key order.
     *
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        $fields = [];

        foreach (UpdateSettingsRequest::FIELDS as $key => $field) {
            $fields[] = ['key' => $key] + $field;
        }

        return $fields;
    }
}
