<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown for a settings key outside SettingsSeeder::DEFAULTS, or a write to a read-only key.
 */
class UnknownSettingException extends InvalidArgumentException
{
    public static function unknown(string $key): self
    {
        return new self("Unknown setting [{$key}].");
    }

    public static function readOnly(string $key): self
    {
        return new self("Setting [{$key}] is read-only and cannot be changed here.");
    }
}
