<?php

namespace App\Support\Roadmap;

/**
 * Colour maths for the roadmap theme: hex <-> sRGB <-> OKLab/OKLCH plus WCAG 2.x
 * relative luminance and contrast ratio. Pure functions, no framework dependencies,
 * so ThemeBuilder stays server-authoritative and unit-testable.
 */
final class ColorContrast
{
    public const WCAG_AA = 4.5;

    /** True for "#rrggbb" (case-insensitive). */
    public static function isValidHex(mixed $hex): bool
    {
        return is_string($hex) && preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function hexToRgb(string $hex): array
    {
        if (! self::isValidHex($hex)) {
            throw new \InvalidArgumentException('Invalid hex colour.');
        }

        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }

    public static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }

    // ─── WCAG ────────────────────────────────────────────────────────

    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = array_map(fn (int $c) => self::srgbToLinear($c / 255), self::hexToRgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** Black or white, whichever reads better on $background. */
    public static function bestForeground(string $background): string
    {
        return self::contrastRatio($background, '#ffffff') >= self::contrastRatio($background, '#000000')
            ? '#ffffff'
            : '#000000';
    }

    // ─── OKLCH ───────────────────────────────────────────────────────

    /** @return array{0:float,1:float,2:float} [L 0..1, C >= 0, h degrees 0..360] */
    public static function hexToOklch(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $c) => self::srgbToLinear($c / 255), self::hexToRgb($hex));

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        $L = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $C = sqrt($a * $a + $bb * $bb);
        $h = $C < 1e-6 ? 0.0 : rad2deg(atan2($bb, $a));
        if ($h < 0) {
            $h += 360;
        }

        return [$L, $C, $h];
    }

    /**
     * OKLCH -> hex. Out-of-gamut colours are brought in by reducing chroma
     * (keeping lightness and hue), which is what browsers do for oklch().
     */
    public static function oklchToHex(float $L, float $C, float $h): string
    {
        $L = max(0.0, min(1.0, $L));
        $C = max(0.0, $C);

        for ($i = 0; $i < 40; $i++) {
            $rgb = self::oklchToLinearRgb($L, $C, $h);
            if (self::inGamut($rgb)) {
                break;
            }
            $C *= 0.92;
            if ($C < 1e-4) {
                $C = 0.0;
                $rgb = self::oklchToLinearRgb($L, 0.0, $h);
                break;
            }
        }

        $rgb = self::oklchToLinearRgb($L, $C, $h);

        return self::rgbToHex(
            (int) round(self::linearToSrgb(max(0.0, min(1.0, $rgb[0]))) * 255),
            (int) round(self::linearToSrgb(max(0.0, min(1.0, $rgb[1]))) * 255),
            (int) round(self::linearToSrgb(max(0.0, min(1.0, $rgb[2]))) * 255),
        );
    }

    /**
     * Move the lightness of $hex (hue/chroma preserved) until it reaches $minRatio
     * against every colour in $against. Direction is chosen from the backgrounds:
     * darken over light surfaces, lighten over dark ones. Returns the original colour
     * when it already complies. Returns null if no lightness satisfies the ratio.
     *
     * @param  string|string[]  $against
     */
    public static function ensureContrast(string $hex, string|array $against, float $minRatio = self::WCAG_AA): ?string
    {
        $against = (array) $against;
        $ok = fn (string $c) => self::meetsAll($c, $against, $minRatio);

        if ($ok($hex)) {
            return $hex;
        }

        [$L, $C, $h] = self::hexToOklch($hex);
        $avgLum = array_sum(array_map(fn ($c) => self::relativeLuminance($c), $against)) / count($against);
        $direction = $avgLum > 0.4 ? -1 : 1;

        $limit = $direction < 0 ? 0.0 : 1.0;
        $current = $L;
        for ($i = 0; $i < 120; $i++) {
            $current += $direction * 0.01;
            $bounded = $direction < 0 ? max($limit, $current) : min($limit, $current);
            $candidate = self::oklchToHex($bounded, $C, $h);
            if ($ok($candidate)) {
                return $candidate;
            }
            if ($bounded === $limit) {
                break;
            }
        }

        return null;
    }

    /** Mix $hex over $base with the given opacity (0..1) in sRGB, returns solid hex. */
    public static function mix(string $hex, string $base, float $opacity): string
    {
        $a = self::hexToRgb($hex);
        $b = self::hexToRgb($base);

        return self::rgbToHex(
            (int) round($a[0] * $opacity + $b[0] * (1 - $opacity)),
            (int) round($a[1] * $opacity + $b[1] * (1 - $opacity)),
            (int) round($a[2] * $opacity + $b[2] * (1 - $opacity)),
        );
    }

    // ─── Internals ───────────────────────────────────────────────────

    /** @param string[] $against */
    private static function meetsAll(string $hex, array $against, float $min): bool
    {
        foreach ($against as $bg) {
            if (self::contrastRatio($hex, $bg) < $min) {
                return false;
            }
        }

        return true;
    }

    private static function srgbToLinear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function linearToSrgb(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }

    private static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }

    /** @return array{0:float,1:float,2:float} */
    private static function oklchToLinearRgb(float $L, float $C, float $h): array
    {
        $a = $C * cos(deg2rad($h));
        $b = $C * sin(deg2rad($h));

        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    private static function inGamut(array $rgb): bool
    {
        foreach ($rgb as $c) {
            if ($c < -0.0005 || $c > 1.0005) {
                return false;
            }
        }

        return true;
    }
}
