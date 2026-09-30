<?php

namespace App\Services\Roadmap;

use App\Support\Roadmap\ColorContrast;

/**
 * Turns `branding.primary` into the public theme (CSS variable tokens for light and dark).
 * The server is the single colour authority: every token pair that carries text is
 * verified against WCAG AA (4.5:1) here, so the client never has to compute contrast.
 *
 * Contract (see PublicTheme in the frontend types):
 *   light/dark: --primary, --primary-foreground, --ring, --rm-accent-soft,
 *               --rm-accent-strong, --chart-1 .. --chart-5   (all "#rrggbb")
 *   radius:     sm 0.5rem | md 0.75rem | lg 0.875rem | xl 1.25rem
 *   font:       outfit | system, default_theme: system | light | dark
 */
class ThemeBuilder
{
    /** Page surfaces the tokens must stay legible on. */
    public const LIGHT_SURFACE = '#ffffff';

    public const DARK_SURFACE = '#1a1a1a';

    public const RADII = [
        'sm' => '0.5rem',
        'md' => '0.75rem',
        'lg' => '0.875rem',
        'xl' => '1.25rem',
    ];

    public const FALLBACK_PRIMARY = '#e11d48';

    /**
     * Human readable problems with a branding block (empty when it is valid).
     *
     * @param  array<string, mixed>  $branding
     * @return string[]
     */
    public function problems(array $branding): array
    {
        $primary = $branding['primary'] ?? self::FALLBACK_PRIMARY;

        if (! ColorContrast::isValidHex($primary)) {
            return ['The primary colour must be a hex value like #e11d48.'];
        }

        // Guard rail: the derived tokens must be able to reach AA in both modes.
        try {
            $this->build(['primary' => $primary] + $branding);
        } catch (\RuntimeException) {
            return ['This colour cannot be made readable (4.5:1) on light and dark backgrounds. Pick a different primary colour.'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $branding
     * @return array{light: array<string,string>, dark: array<string,string>, radius: string, font: string, default_theme: string}
     *
     * @throws \InvalidArgumentException on an invalid hex
     * @throws \RuntimeException when contrast cannot be reached
     */
    public function build(array $branding): array
    {
        $primary = strtolower((string) ($branding['primary'] ?? self::FALLBACK_PRIMARY));
        if (! ColorContrast::isValidHex($primary)) {
            throw new \InvalidArgumentException('Invalid primary colour.');
        }

        $radius = self::RADII[$branding['radius'] ?? 'lg'] ?? self::RADII['lg'];
        $font = in_array($branding['font'] ?? 'outfit', ['outfit', 'system'], true) ? ($branding['font'] ?? 'outfit') : 'outfit';
        $default = in_array($branding['default_theme'] ?? 'system', ['system', 'light', 'dark'], true) ? ($branding['default_theme'] ?? 'system') : 'system';

        return [
            'light' => $this->tokens($primary, self::LIGHT_SURFACE, false),
            'dark' => $this->tokens($primary, self::DARK_SURFACE, true),
            'radius' => $radius,
            'font' => $font,
            'default_theme' => $default,
        ];
    }

    /** @return array<string,string> */
    private function tokens(string $brand, string $surface, bool $dark): array
    {
        // "Text-use" primary: darkened on light, lightened on dark until it reads at 4.5:1.
        $primary = ColorContrast::ensureContrast($brand, $surface, ColorContrast::WCAG_AA);
        if ($primary === null) {
            throw new \RuntimeException('Primary cannot reach contrast.');
        }

        $foreground = ColorContrast::bestForeground($primary);
        if (ColorContrast::contrastRatio($primary, $foreground) < ColorContrast::WCAG_AA) {
            throw new \RuntimeException('Foreground cannot reach contrast.');
        }

        $soft = ColorContrast::mix($primary, $surface, $dark ? 0.18 : 0.10);

        // Strong accent: one notch further from the surface, legible on both the surface and the soft tint.
        [$L, $C, $h] = ColorContrast::hexToOklch($primary);
        $strongSeed = ColorContrast::oklchToHex($dark ? min(1.0, $L + 0.05) : max(0.0, $L - 0.06), $C, $h);
        $strong = ColorContrast::ensureContrast($strongSeed, [$surface, $soft], ColorContrast::WCAG_AA);
        if ($strong === null) {
            throw new \RuntimeException('Strong accent cannot reach contrast.');
        }

        $tokens = [
            '--primary' => $primary,
            '--primary-foreground' => $foreground,
            '--ring' => $primary,
            '--rm-accent-soft' => $soft,
            '--rm-accent-strong' => $strong,
        ];

        // Same hue, stepped lightness (kept in a band that stays visible on the surface).
        $chroma = max(0.05, min($C, 0.22));
        $steps = $dark ? [0.58, 0.66, 0.74, 0.82, 0.90] : [0.34, 0.42, 0.50, 0.58, 0.66];
        foreach ($steps as $i => $lightness) {
            $tokens['--chart-'.($i + 1)] = ColorContrast::oklchToHex($lightness, $chroma, $h);
        }

        return $tokens;
    }
}
