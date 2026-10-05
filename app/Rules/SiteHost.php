<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A bare host as the timer extension reports it (docs/extension-api.md §5): lower-case, no
 * scheme, no path, query, fragment, credentials or spaces, and a port only after `localhost`
 * or an IPv4 address. The empty string passes here — whether a host is required is the
 * `kind` rule's question, not this one's.
 */
class SiteHost implements ValidationRule
{
    public const PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::\d{1,5})?$/';

    private const IPV4 = '/^(?:\d{1,3}\.){3}\d{1,3}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === '' || $value === null) {
            return;
        }

        if (! is_string($value) || ! $this->passes($value)) {
            $fail('The site host must be a bare domain such as docs.google.com.');
        }
    }

    private function passes(string $value): bool
    {
        if (preg_match('/[\/?#@\s]/', $value) === 1 || str_contains($value, '://')) {
            return false;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            return false;
        }

        $colon = strrpos($value, ':');

        if ($colon === false) {
            return true;
        }

        $host = substr($value, 0, $colon);

        return $host === 'localhost' || preg_match(self::IPV4, $host) === 1;
    }
}
