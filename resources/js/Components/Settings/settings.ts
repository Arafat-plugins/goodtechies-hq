/**
 * The Admin → Settings contract, as the server sends it.
 *
 * Every shape here is produced by `App\Http\Requests\Settings\UpdateSettingsRequest::FIELDS` and
 * handed over by `Admin/SettingsController::index()`. **Nothing in this folder decides what a
 * setting is** — not its label, not its group, not its bounds, not the sentence under the box.
 * That table is also where `rules()` comes from, which is the point: a number input whose `max`
 * is 60 because the server refuses 61 cannot fall out of step with the server, because it is the
 * same 60.
 */

/**
 * `SettingField.type`. It is the CONTROL and the validation in one word — the server picks the
 * rule set from it and the screen picks the input from it, so a key cannot be validated as an
 * integer and typed into as free text.
 */
export type SettingType = 'timezone' | 'currency' | 'integer' | 'boolean';

/** Anything a setting may hold. `null` is "never set", which only `backup_last_verified_at` is. */
export type SettingValue = string | number | boolean | null;

export interface SettingField {
    key: string;
    /** One of `sections`, which arrives in Part E's own order for this page. */
    section: string;
    label: string;
    type: SettingType;
    /** One plain sentence: what the setting does, and what the ends of its range mean. */
    help: string;
    /** `integer` only — printed beside the box and enforced by the server. */
    unit?: string;
    min?: number;
    max?: number;
    /** `timezone` / `currency` only. */
    placeholder?: string;
    /** `boolean` only: the words beside the switch, which is what carries the state (§5.6). */
    on?: string;
    off?: string;
}

/** The flat key/value list the page is populated from. */
export interface SettingRow {
    key: string;
    value: SettingValue;
}

/** One read-only integration: the driver, where it is set, and why it cannot move at runtime. */
export interface IntegrationRow {
    label: string;
    value: string;
    env: string;
    why: string;
}

/**
 * The stored values as a lookup, so a field finds its own value without scanning the list.
 */
export function valuesByKey(rows: SettingRow[]): Record<string, SettingValue> {
    const values: Record<string, SettingValue> = {};

    for (const row of rows) {
        values[row.key] = row.value;
    }

    return values;
}
