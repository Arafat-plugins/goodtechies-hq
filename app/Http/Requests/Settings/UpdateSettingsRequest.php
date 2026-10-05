<?php

namespace App\Http\Requests\Settings;

use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin → Settings, the write half (master prompt Part E, Phase 12: *"settings changes
 * audit-logged and **every key except `backup_last_verified_at` editable**"*).
 *
 * ## `FIELDS` is the whole contract, and it is one table
 *
 * Every key in Part D §20 appears below exactly once, with the group it is shown in, the type it
 * is, the range it may hold and the sentence the screen prints under it. `rules()` is generated
 * from it and `SettingsController::index()` sends it to the screen, so the control somebody sees,
 * the range the server enforces and the help text under the box cannot disagree — there is no
 * second list in Vue to keep in step. Part D §20's list is **closed** (*"Nothing else is added
 * without a recorded decision"*), and `after()` below refuses a key that is not in it, so this
 * table can only ever be a subset of `SettingsSeeder::DEFAULTS`.
 *
 * ## Why the ranges are what they are
 *
 * A form that accepts any string for every key can break attendance derivation with a typo, so
 * each one is bounded at both ends by the rule that reads it:
 *
 *   - **`timezone`** — an IANA identifier, validated against PHP's own list. It is what every
 *     "today" in the application resolves through (`AttendanceService`, `hq:mark-absent`, every
 *     report's day boundary); a value Carbon cannot construct a timezone from is not a wrong
 *     answer, it is an exception on the next scheduled run.
 *   - **`currency`** — three letters, upper-cased on the way in. ISO 4217 alpha-3 is the shape
 *     every money label in finance, payroll and the payslip prints. Not an allow-list of codes:
 *     Part H §2 records the value as *"single value; spec examples use `$`"* and *"payroll
 *     currency to be confirmed"*, so closing the list here would be a decision nobody has made.
 *   - **`late_grace_minutes` 0–120.** 0 is meaningful — no grace, a minute late is Late. 120 is
 *     the far end at which the rule stops meaning anything: two hours after a 09:00 start is
 *     most of a morning, and an agency that wants that does not want a Late rule.
 *   - **`half_day_auto`** — boolean (Part H §2 default: off).
 *   - **`timer_max_session_hours` 1–24.** At 0 the safeguard would trip on the first tick of
 *     every session; past 24 a session outlives the day it started in, which is the one thing
 *     the safeguard exists to stop.
 *   - **`heartbeat_timeout_minutes` 1–60.** At 0 a timer dies between two heartbeats. Past an
 *     hour a closed laptop banks an hour of work — Part H §2's reason for the setting is *"a
 *     closed laptop must not log hours"*, so an hour is the outer edge of the promise.
 *   - **`manual_time_requires_approval`** — boolean (Part H §2 default: on).
 *   - **`notification_group_window_minutes` 0–60.** 0 is a real answer and the engine already
 *     honours it: `NotificationService::windowStart()` treats a window of zero or less as
 *     grouping **off** rather than grouping everything. 60 is the cap because a window longer
 *     than an hour folds a whole morning's comments into one row and the reader loses the
 *     second half of the conversation.
 *   - **`idle_pause_minutes` 1–60.** At 0 the timer pauses on the first idle sample of every
 *     session. Past an hour the pause arrives after the idleness it was meant to catch.
 *   - **`idle_flag_percent` 0–100.** A percentage of one entry, so its own arithmetic is the
 *     range. 0 flags everything and 100 flags nothing, and both are legible choices.
 *   - **`idle_prompt_seconds` 30–1800.** How long the extension waits before asking whether to keep idle time.
 *   - **`activity_retention_days` 7–730.** How long per-minute activity and site rows are kept.
 *
 * `backup_last_verified_at` is **not** here. `SettingsService::READ_ONLY` already refuses it and
 * `hq:verify-backup` is its only writer; leaving it out of this table is what makes the screen
 * draw it as a read-only card rather than as a field somebody can type a date into.
 *
 * ## The payload is partial, and an unrecognised key is an error rather than a no-op
 *
 * Each section of the screen saves its own keys, so every rule is `sometimes`. The risk that
 * creates is a key that is misspelled somewhere in the chain: it would validate (nothing
 * claims it), save nothing, and flash success. `after()` closes it — any input key outside
 * `FIELDS` fails validation by name, and a request carrying none of them fails too, so
 * "saved" always means something was written or was already that value.
 *
 * Authorization is `can:settings.manage` on the route, and `SettingsService::set()` asks the
 * same permission again for a caller that is not an HTTP request.
 */
class UpdateSettingsRequest extends FormRequest
{
    /**
     * Part D §20's keys, minus the read-only one: what each is, what it may hold, and what the
     * screen says about it.
     *
     * @var array<string, array<string, mixed>>
     */
    public const FIELDS = [
        'timezone' => [
            'section' => 'General',
            'label' => 'Timezone',
            'type' => 'timezone',
            'help' => 'An IANA identifier such as Asia/Dhaka. Every day boundary in the app — attendance, the absent sweep, every report — is resolved in this zone.',
            'placeholder' => 'Asia/Dhaka',
        ],
        'currency' => [
            'section' => 'General',
            'label' => 'Currency',
            'type' => 'currency',
            'help' => 'A three-letter ISO code such as USD. It labels every amount in finance, payroll and payslips; it does not convert anything.',
            'placeholder' => 'USD',
        ],
        'late_grace_minutes' => [
            'section' => 'Attendance',
            'label' => 'Late grace period',
            'type' => 'integer',
            'min' => 0,
            'max' => 120,
            'unit' => 'minutes',
            'help' => 'How long after their start time someone may clock in and still be On time. 0 means a minute late is Late.',
        ],
        'half_day_auto' => [
            'section' => 'Attendance',
            'label' => 'Mark half days automatically',
            'type' => 'boolean',
            'help' => 'Off means an Admin marks a half day by hand.',
            'on' => 'On — a short day is marked Half day by itself',
            'off' => 'Off — an Admin marks a half day by hand',
        ],
        'timer_max_session_hours' => [
            'section' => 'Timer',
            'label' => 'Maximum timer session',
            'type' => 'integer',
            'min' => 1,
            'max' => 24,
            'unit' => 'hours',
            'help' => 'A running timer this long is paused and flagged for review — the safeguard against one left on overnight.',
        ],
        'heartbeat_timeout_minutes' => [
            'section' => 'Timer',
            'label' => 'Heartbeat timeout',
            'type' => 'integer',
            'min' => 1,
            'max' => 60,
            'unit' => 'minutes',
            'help' => 'Silence this long ends the session at the last heartbeat and flags it, so a closed laptop does not log hours.',
        ],
        'manual_time_requires_approval' => [
            'section' => 'Timer',
            'label' => 'Manual time needs approval',
            'type' => 'boolean',
            'help' => 'Whether hours typed in by hand wait for an Admin before they count.',
            'on' => 'On — typed-in hours wait for an Admin',
            'off' => 'Off — typed-in hours count straight away',
        ],
        'notification_group_window_minutes' => [
            'section' => 'Notifications',
            'label' => 'Group repeat notifications within',
            'type' => 'integer',
            'min' => 0,
            'max' => 60,
            'unit' => 'minutes',
            'help' => 'Repeated events on the same thing inside this window become one notification with a count. 0 turns grouping off.',
        ],
        'idle_pause_minutes' => [
            'section' => 'Timer',
            'label' => 'Pause the timer after idle for',
            'type' => 'integer',
            'min' => 1,
            'max' => 60,
            'unit' => 'minutes',
            'help' => 'Consecutive idle minutes before the timer offers to pause. Watching a video does not count as idle.',
        ],
        'idle_flag_percent' => [
            'section' => 'Timer',
            'label' => 'Flag an entry idle for more than',
            'type' => 'integer',
            'min' => 0,
            'max' => 100,
            'unit' => '%',
            'help' => 'How much of one entry may be idle before it is marked for an Admin to look at.',
        ],
        'idle_prompt_seconds' => [
            'section' => 'Timer',
            'label' => 'Ask "are you still working?" after idle for',
            'type' => 'integer',
            'min' => 30,
            'max' => 1800,
            'unit' => 'seconds',
            'help' => 'How long a remote timer may sit with no keyboard, mouse, video or call before the extension asks whether to keep the time.',
        ],
        'activity_retention_days' => [
            'section' => 'Timer',
            'label' => 'Keep per-minute activity and site data for',
            'type' => 'integer',
            'min' => 7,
            'max' => 730,
            'unit' => 'days',
            'help' => 'Minute-by-minute activity and website time older than this is deleted every night. Totals per entry stay.',
        ],
    ];

    /**
     * The order the screen draws the groups in — Part E's own order for this page.
     *
     * @var list<string>
     */
    public const SECTIONS = ['General', 'Attendance', 'Timer', 'Notifications'];

    /**
     * One rule set per key, generated from `FIELDS` so the range on screen is the range enforced.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (self::FIELDS as $key => $field) {
            $rules[$key] = match ($field['type']) {
                // PHP's own identifier list. `Rule::in(DateTimeZone::listIdentifiers())` would
                // say the same thing in a message nobody could read.
                'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
                // Shape, not membership — see the class docblock on why the list stays open.
                'currency' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
                'boolean' => ['sometimes', 'required', 'boolean'],
                'integer' => ['sometimes', 'required', 'integer', 'min:'.$field['min'], 'max:'.$field['max']],
            };
        }

        return $rules;
    }

    /**
     * Two things `rules()` cannot say, because both are about the payload as a whole.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sent = array_keys($this->except(['_token', '_method']));
                $unknown = array_values(array_diff($sent, array_keys(self::FIELDS)));

                foreach ($unknown as $key) {
                    // Named individually so the message says which key, and so a read-only key
                    // gets the sentence that explains itself rather than "not a setting".
                    $validator->errors()->add($key, $this->unknownKeyMessage((string) $key));
                }

                if ($unknown === [] && array_intersect($sent, array_keys(self::FIELDS)) === []) {
                    $validator->errors()->add(
                        'settings',
                        'There is nothing to save — send at least one setting.',
                    );
                }
            },
        ];
    }

    /**
     * The validated keys, each cast to the type the setting actually stores.
     *
     * This matters more than it looks: `Setting::value` is a JSON column and
     * `SettingsService::set()` compares old and new with `===` before it writes. A `"15"` from
     * a form field is not `15`, so an uncast payload would write a string into a column every
     * reader expects an integer in, and would write it again on every save because the
     * comparison could never match.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];

        foreach ($this->validated() as $key => $value) {
            if (! array_key_exists($key, self::FIELDS)) {
                continue;
            }

            $changes[$key] = match (self::FIELDS[$key]['type']) {
                'boolean' => $this->boolean($key),
                'integer' => (int) $value,
                // Upper-cased so `usd` and `USD` are the same stored value rather than two.
                'currency' => strtoupper(trim((string) $value)),
                default => trim((string) $value),
            };
        }

        return $changes;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (self::FIELDS as $key => $field) {
            $attributes[$key] = strtolower((string) $field['label']);
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency.regex' => 'The currency is a three-letter code, such as USD.',
            'currency.size' => 'The currency is a three-letter code, such as USD.',
            'timezone.timezone' => 'That is not a timezone name. Use an IANA identifier such as Asia/Dhaka.',
        ];
    }

    private function unknownKeyMessage(string $key): string
    {
        if (in_array($key, SettingsService::READ_ONLY, true)) {
            return 'That setting is written by the system and cannot be changed here.';
        }

        return array_key_exists($key, SettingsSeeder::DEFAULTS)
            ? 'That setting is not editable.'
            : 'There is no such setting.';
    }
}
