<?php

namespace Tests\Support;

/**
 * An sRGB colour with an alpha channel, and the WCAG 2.x arithmetic over it.
 *
 * This exists so a contrast claim in DESIGN.md is a number a test recomputed rather than a number
 * somebody typed. `--ring` sat in DESIGN.md §2.2 at 3.45:1 ✅ for eleven phases while the
 * application rendered it at 50 % and 1.90:1 — the ratio was right about the token and wrong
 * about the screen, and nothing re-derived it.
 *
 * Channels are held as 8-bit integers because that is what the compositor and the eye get: the
 * oklch value is converted, clipped into the sRGB gamut and quantised once, then composited.
 */
final class Colour
{
    private function __construct(
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
        public readonly float $alpha = 1.0,
    ) {}

    public static function rgb(int $r, int $g, int $b, float $alpha = 1.0): self
    {
        return new self($r, $g, $b, $alpha);
    }

    /**
     * oklch -> Oklab -> linear sRGB -> gamma-encoded sRGB, clipped per channel.
     *
     * Clipping rather than gamut-mapping is deliberate: it is what a browser does with an
     * out-of-gamut oklch() today, so it is what the screen shows.
     */
    public static function oklch(float $l, float $c, float $hDegrees, float $alpha = 1.0): self
    {
        $h = deg2rad($hDegrees);
        $a = $c * cos($h);
        $bb = $c * sin($h);

        $lCube = ($l + 0.3963377774 * $a + 0.2158037573 * $bb) ** 3;
        $mCube = ($l - 0.1055613458 * $a - 0.0638541728 * $bb) ** 3;
        $sCube = ($l - 0.0894841775 * $a - 1.2914855480 * $bb) ** 3;

        $linear = [
            4.0767416621 * $lCube - 3.3077115913 * $mCube + 0.2309699292 * $sCube,
            -1.2684380046 * $lCube + 2.6097574011 * $mCube - 0.3413193965 * $sCube,
            -0.0041960863 * $lCube - 0.7034186147 * $mCube + 1.7076147010 * $sCube,
        ];

        $encoded = array_map(static function (float $u): int {
            $u = max(0.0, min(1.0, $u));
            $v = $u <= 0.0031308 ? 12.92 * $u : 1.055 * $u ** (1 / 2.4) - 0.055;

            return (int) round($v * 255);
        }, $linear);

        return new self($encoded[0], $encoded[1], $encoded[2], $alpha);
    }

    /** Source-over composite of this colour onto an opaque background. */
    public function over(self $background): self
    {
        if ($this->alpha >= 1.0) {
            return new self($this->r, $this->g, $this->b);
        }

        $mix = fn (int $fg, int $bg): int => (int) round($fg * $this->alpha + $bg * (1 - $this->alpha));

        return new self(
            $mix($this->r, $background->r),
            $mix($this->g, $background->g),
            $mix($this->b, $background->b),
        );
    }

    public function withAlpha(float $alpha): self
    {
        return new self($this->r, $this->g, $this->b, $alpha);
    }

    public function relativeLuminance(): float
    {
        $channel = static function (int $eight): float {
            $c = $eight / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($this->r) + 0.7152 * $channel($this->g) + 0.0722 * $channel($this->b);
    }

    /** WCAG 2.x contrast ratio. Both colours must already be opaque — composite first. */
    public static function contrast(self $a, self $b): float
    {
        $la = $a->relativeLuminance();
        $lb = $b->relativeLuminance();

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public function hex(): string
    {
        return sprintf('#%02X%02X%02X', $this->r, $this->g, $this->b);
    }
}
