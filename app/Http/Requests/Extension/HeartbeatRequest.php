<?php

namespace App\Http\Requests\Extension;

use App\Http\Requests\Time\TimerRequest;
use App\Rules\SiteHost;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * A batch of activity minutes from the extension (docs/extension-api.md §5).
 *
 * Only a host is ever accepted for a site — never a URL, a path, a query or a page title — and a
 * payload that carries a key named `url`, `title` or `hostname` anywhere is refused whole.
 */
class HeartbeatRequest extends TimerRequest
{
    private const FORBIDDEN_KEYS = ['url', 'title', 'hostname'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'time_entry_id' => ['required', 'integer'],
            'client_uuid' => ['required', 'uuid'],
            'samples' => ['required', 'array', 'min:1', 'max:120'],
            'samples.*.minute' => ['required', 'date'],
            'samples.*.state' => ['required', 'in:active,media,call,idle'],
            'samples.*.call_source' => ['nullable', 'required_if:samples.*.state,call', 'in:detected,manual'],
            'samples.*.sites' => ['present', 'array', 'max:20'],
            'samples.*.sites.*.kind' => ['required', 'in:site,other_app,browser_internal,private'],
            // Nullable because the global ConvertEmptyStringsToNull middleware turns the `""` a
            // non-site row carries into null before validation runs.
            'samples.*.sites.*.host' => ['present', 'nullable', 'string', 'max:253', 'required_if:samples.*.sites.*.kind,site', new SiteHost],
            'samples.*.sites.*.seconds' => ['required', 'integer', 'min:0', 'max:60'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $path = $this->forbiddenKey($this->all(), '');

                if ($path !== null) {
                    $validator->errors()->add($path, 'The payload may not carry a url, title or hostname.');
                }

                foreach ((array) $this->input('samples', []) as $i => $sample) {
                    $sites = is_array($sample) ? ($sample['sites'] ?? []) : [];

                    if (! is_array($sites)) {
                        continue;
                    }

                    $sum = 0;

                    foreach ($sites as $j => $site) {
                        if (! is_array($site)) {
                            continue;
                        }

                        $sum += is_numeric($site['seconds'] ?? null) ? (int) $site['seconds'] : 0;

                        $kind = $site['kind'] ?? null;
                        $host = $site['host'] ?? null;

                        if ($kind !== 'site' && $host !== null && $host !== '') {
                            $validator->errors()->add("samples.$i.sites.$j.host", 'The host must be empty for anything that is not a site.');
                        }
                    }

                    if ($sum > 60) {
                        $validator->errors()->add("samples.$i.sites", 'The sites of one minute may not add up to more than 60 seconds.');
                    }
                }
            },
        ];
    }

    public function timeEntryId(): int
    {
        return (int) $this->validated('time_entry_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function samples(): array
    {
        return array_values((array) $this->validated('samples'));
    }

    /**
     * The dotted path of the first key named `url`, `title` or `hostname`, at any depth.
     */
    private function forbiddenKey(mixed $value, string $prefix): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                return $path;
            }

            $found = $this->forbiddenKey($child, $path);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
