<?php

namespace Tests\Support;

use RuntimeException;

/**
 * The token values as `resources/css/app.css` actually defines them, per theme.
 *
 * DESIGN.md is generated from `app.css` and `app.css` wins whenever they disagree, so a test that
 * wants to know what the screen shows reads the stylesheet rather than the document. `var(--x)`
 * chains are resolved (`--ring: var(--brand)`), and the alpha modifier on the global focus outline
 * in the `@layer base` block is read out too — that modifier is the whole of §E.1.
 */
final class TokenSheet
{
    private const PATH = 'resources/css/app.css';

    /** @param array<string, string> $declarations */
    private function __construct(
        public readonly string $theme,
        private readonly array $declarations,
        private readonly string $source,
    ) {}

    public static function light(): self
    {
        return self::block('light', '/^:root\s*\{(.*?)^\}/ms');
    }

    public static function dark(): self
    {
        return self::block('dark', '/^\.dark\s*\{(.*?)^\}/ms');
    }

    private static function block(string $theme, string $pattern): self
    {
        $source = self::source();

        if (preg_match($pattern, $source, $match) !== 1) {
            throw new RuntimeException("Could not find the {$theme} token block in ".self::PATH);
        }

        preg_match_all('/^\s*(--[a-z0-9-]+)\s*:\s*([^;]+);/mi', $match[1], $found, PREG_SET_ORDER);

        $declarations = [];
        foreach ($found as $row) {
            $declarations[$row[1]] = trim($row[2]);
        }

        return new self($theme, $declarations, $source);
    }

    public static function source(): string
    {
        // Resolved off this file rather than base_path(): these are pure-arithmetic Unit tests
        // and they run on the plain PHPUnit case, with no Laravel application booted.
        $path = dirname(__DIR__, 2).'/'.self::PATH;

        if (! is_file($path)) {
            throw new RuntimeException('Missing '.self::PATH);
        }

        return (string) file_get_contents($path);
    }

    /** The declared value with any `var(--x)` indirection followed. */
    public function value(string $token): string
    {
        $seen = [];

        while (true) {
            if (! array_key_exists($token, $this->declarations)) {
                throw new RuntimeException("{$this->theme}: no such token {$token}");
            }

            $value = $this->declarations[$token];

            if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*\)$/i', $value, $match) !== 1) {
                return $value;
            }

            if (isset($seen[$token])) {
                throw new RuntimeException("{$this->theme}: var() cycle at {$token}");
            }

            $seen[$token] = true;
            $token = $match[1];
        }
    }

    /** A token as a colour. `oklch(L C H)` and `oklch(L C H / P%)` are the only forms app.css uses. */
    public function colour(string $token): Colour
    {
        $value = $this->value($token);

        if (preg_match('/^oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*(?:\/\s*([\d.]+)(%?)\s*)?\)$/i', $value, $m) !== 1) {
            throw new RuntimeException("{$this->theme}: {$token} is not an oklch() value: {$value}");
        }

        $alpha = 1.0;
        if (($m[4] ?? '') !== '') {
            $alpha = ($m[5] ?? '') === '%' ? (float) $m[4] / 100 : (float) $m[4];
        }

        return Colour::oklch((float) $m[1], (float) $m[2], (float) $m[3], $alpha);
    }

    /**
     * The opacity the global focus outline is rendered at, read out of the `@layer base` rule.
     *
     * `outline-ring` is 1.0; `outline-ring/50` is 0.5, which is what shipped from Phase 0.5 to
     * Phase 12 and what put every focus indicator in the application under 3:1.
     */
    public static function globalOutlineAlpha(): float
    {
        if (preg_match('/@apply[^;]*\boutline-ring(?:\/(\d+))?\b/', self::source(), $match) !== 1) {
            throw new RuntimeException('No `@apply … outline-ring` rule found in '.self::PATH);
        }

        return isset($match[1]) && $match[1] !== '' ? ((int) $match[1]) / 100 : 1.0;
    }
}
