<?php

namespace Tests\Feature\Roadmap;

use App\Services\Roadmap\ThemeBuilder;
use App\Support\Roadmap\ColorContrast;
use PHPUnit\Framework\Attributes\DataProvider;

class ThemeContrastTest extends RoadmapTestCase
{
    use AdminApiTrait;

    /** @return array<string, array{string}> */
    public static function brandColours(): array
    {
        $colours = [
            'default rose' => '#e11d48',
            'yellow' => '#facc15',
            'pure yellow' => '#ffff00',
            'near white' => '#fefefe',
            'white' => '#ffffff',
            'off white warm' => '#fffbeb',
            'black' => '#000000',
            'near black' => '#0a0a0a',
            'pure blue' => '#0000ff',
            'pure green' => '#00ff00',
            'navy' => '#1e3a8a',
            'mid grey' => '#808080',
            'cyan' => '#22d3ee',
            'orange' => '#f97316',
            'purple' => '#7c3aed',
            'pastel pink' => '#fbcfe8',
        ];

        return array_map(fn ($c) => [$c], $colours);
    }

    #[DataProvider('brandColours')]
    public function test_every_generated_text_pair_reaches_wcag_aa(string $primary): void
    {
        $theme = app(ThemeBuilder::class)->build(['primary' => $primary]);

        foreach (['light' => ThemeBuilder::LIGHT_SURFACE, 'dark' => ThemeBuilder::DARK_SURFACE] as $mode => $surface) {
            $t = $theme[$mode];
            $soft = $t['--rm-accent-soft'];

            $pairs = [
                "$mode primary on surface" => [$t['--primary'], $surface],
                "$mode primary-foreground on primary" => [$t['--primary-foreground'], $t['--primary']],
                "$mode accent-strong on surface" => [$t['--rm-accent-strong'], $surface],
                "$mode accent-strong on accent-soft" => [$t['--rm-accent-strong'], $soft],
            ];

            foreach ($pairs as $label => [$fg, $bg]) {
                $this->assertGreaterThanOrEqual(
                    4.5,
                    ColorContrast::contrastRatio($fg, $bg),
                    "$label failed for brand $primary ($fg on $bg)"
                );
            }

            $this->assertContains($t['--primary-foreground'], ['#000000', '#ffffff']);
            foreach (['--ring', '--rm-accent-soft', '--chart-1', '--chart-2', '--chart-3', '--chart-4', '--chart-5'] as $token) {
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $t[$token], "$mode $token");
            }
        }
    }

    public function test_theme_carries_radius_font_and_default_theme(): void
    {
        $builder = app(ThemeBuilder::class);

        $this->assertSame('0.5rem', $builder->build(['primary' => '#112233', 'radius' => 'sm'])['radius']);
        $this->assertSame('0.75rem', $builder->build(['primary' => '#112233', 'radius' => 'md'])['radius']);
        $this->assertSame('0.875rem', $builder->build(['primary' => '#112233', 'radius' => 'lg'])['radius']);
        $this->assertSame('1.25rem', $builder->build(['primary' => '#112233', 'radius' => 'xl'])['radius']);

        $theme = $builder->build(['primary' => '#112233', 'font' => 'system', 'default_theme' => 'dark']);
        $this->assertSame('system', $theme['font']);
        $this->assertSame('dark', $theme['default_theme']);
        $this->assertSame(['light', 'dark', 'radius', 'font', 'default_theme'], array_keys($theme));
    }

    public function test_a_colour_that_already_complies_is_kept_in_light_mode(): void
    {
        $theme = app(ThemeBuilder::class)->build(['primary' => '#1e3a8a']);

        $this->assertSame('#1e3a8a', $theme['light']['--primary']);
        // On the dark surface the same navy is too dark and gets lightened.
        $this->assertNotSame('#1e3a8a', $theme['dark']['--primary']);
    }

    public function test_invalid_hex_values_are_rejected(): void
    {
        $builder = app(ThemeBuilder::class);

        foreach (['red', '#fff', '#gggggg', 'e11d48', '#e11d48ff', '', 123] as $bad) {
            $this->assertNotEmpty($builder->problems(['primary' => $bad]), 'accepted: '.var_export($bad, true));
        }
        $this->assertSame([], $builder->problems(['primary' => '#E11D48']));

        $this->expectException(\InvalidArgumentException::class);
        $builder->build(['primary' => 'not-a-colour']);
    }

    public function test_color_math_basics(): void
    {
        $this->assertEqualsWithDelta(21.0, ColorContrast::contrastRatio('#000000', '#ffffff'), 0.001);
        $this->assertEqualsWithDelta(1.0, ColorContrast::contrastRatio('#123456', '#123456'), 0.001);
        $this->assertEqualsWithDelta(4.0, ColorContrast::contrastRatio('#777777', '#ffffff'), 0.6); // #777 is ~4.48

        // sRGB -> OKLCH -> sRGB round trip (in gamut colours).
        foreach (['#e11d48', '#3b82f6', '#10b981', '#f59e0b', '#808080'] as $hex) {
            [$l, $c, $h] = ColorContrast::hexToOklch($hex);
            $this->assertSame($hex, ColorContrast::oklchToHex($l, $c, $h));
        }

        [$white] = ColorContrast::hexToOklch('#ffffff');
        $this->assertEqualsWithDelta(1.0, $white, 0.01);
        $this->assertSame('#000000', ColorContrast::bestForeground('#facc15'));
        $this->assertSame('#ffffff', ColorContrast::bestForeground('#1e3a8a'));
    }

    public function test_settings_api_rejects_an_invalid_primary_and_accepts_an_awkward_one(): void
    {
        $this->asAdmin();

        $this->putJson(self::ADMIN_API.'/settings', ['scope' => 'global', 'data' => ['branding' => ['primary' => 'blue']]])
            ->assertStatus(422)->assertJsonValidationErrors('data.branding.primary');

        $this->putJson(self::ADMIN_API.'/settings', ['scope' => 'global', 'data' => ['branding' => ['primary' => '#ffff00']]])
            ->assertOk()
            ->assertJsonPath('data.settings.branding.primary', '#ffff00');

        $theme = $this->getJson(self::ADMIN_API.'/settings?scope=global')->assertOk()->json('data.theme');
        $this->assertGreaterThanOrEqual(4.5, ColorContrast::contrastRatio($theme['light']['--primary'], '#ffffff'));

        $this->postJson(self::ADMIN_API.'/settings/theme-preview', ['branding' => ['primary' => 'nope']])
            ->assertStatus(422);
        $this->postJson(self::ADMIN_API.'/settings/theme-preview', ['branding' => ['primary' => '#fefefe', 'radius' => 'xl']])
            ->assertOk()
            ->assertJsonPath('data.radius', '1.25rem')
            ->assertJsonStructure(['data' => ['light' => ['--primary', '--primary-foreground', '--ring', '--rm-accent-soft', '--rm-accent-strong', '--chart-1', '--chart-5'], 'dark', 'radius', 'font', 'default_theme']]);
    }
}
