<?php

namespace App\Services\Roadmap;

/**
 * Shape, defaults and validation rules of the roadmap settings JSON.
 *
 * Stored blob (per scope): site / branding / features / moderation / limits / seo.
 * Branding asset paths are stored as branding.logo, logo_dark, favicon, og (disk-relative)
 * and exposed to the admin API as *_path keys. Uploads own those keys: PUT never writes them.
 */
class SettingsSchema
{
    public const GLOBAL_SCOPE = 'global';

    /** Board scopes may override only these dotted keys. */
    public const BOARD_OVERRIDABLE = [
        'site.hero_title',
        'site.hero_subtitle',
        'branding.primary',
        'branding.radius',
        'branding.hero_style',
    ];

    /** Text fields that are null (not an empty string) when cleared. */
    private const NULLABLE_TEXT = ['tagline', 'hero_subtitle', 'footer_text', 'contact_url', 'default_board_slug'];

    /** Storage key => admin/public output key. */
    public const ASSET_KEYS = [
        'logo' => 'logo_path',
        'logo_dark' => 'logo_dark_path',
        'favicon' => 'favicon_path',
        'og' => 'og_image_path',
    ];

    /** Upload type => storage key. */
    public const ASSET_TYPES = [
        'logo' => 'logo',
        'logo_dark' => 'logo_dark',
        'favicon' => 'favicon',
        'og' => 'og',
    ];

    /** @return array<string, array<string, mixed>> */
    public function defaults(): array
    {
        $configured = config('roadmap.defaults');

        return $this->deepMerge($this->builtInDefaults(), is_array($configured) ? $configured : []);
    }

    /** @return array<string, array<string, mixed>> */
    public function builtInDefaults(): array
    {
        return [
            'site' => [
                'name' => 'PNE Roadmap',
                'tagline' => 'Tell us what to build next',
                'hero_title' => 'What should we build next?',
                'hero_subtitle' => 'Vote on ideas, follow progress, and see what just shipped.',
                'team_name' => 'PNE Team',
                'footer_text' => null,
                'footer_links' => [],
                'contact_url' => null,
                'default_board_slug' => null,
            ],
            'branding' => [
                'primary' => '#e11d48',
                'radius' => 'lg',
                'font' => 'outfit',
                'default_theme' => 'system',
                'hero_style' => 'gradient',
                'logo' => null,
                'logo_dark' => null,
                'favicon' => null,
                'og' => null,
            ],
            'features' => [
                'roadmap' => true,
                'changelog' => true,
                'comments' => true,
                'show_vote_counts' => true,
                'rss' => true,
            ],
            'moderation' => [
                'blocklist' => [],
                'max_links_post' => 2,
                'max_links_comment' => 1,
                'min_post_seconds' => 5,
                'min_comment_seconds' => 3,
            ],
            'limits' => [
                'votes_per_visitor_day' => 30,
                'votes_per_ip_day' => 120,
                'new_visitor_votes_day' => 10,
                'posts_per_visitor_day' => 5,
                'posts_per_ip_day' => 15,
                'comments_per_visitor_hour' => 10,
                'tokens_per_ip_day' => 20,
            ],
            'seo' => [
                'indexable' => true,
                'title_suffix' => '',
                'meta_description' => '',
            ],
        ];
    }

    /**
     * Laravel validation rules for the `data` object of PUT /settings.
     * Every section is optional so the console can save one tab at a time.
     *
     * @return array<string, mixed>
     */
    public function rules(string $scope = self::GLOBAL_SCOPE): array
    {
        $all = [
            'data' => ['required', 'array'],
            'data.site' => ['sometimes', 'array'],
            'data.site.name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'data.site.tagline' => ['sometimes', 'nullable', 'string', 'max:140'],
            'data.site.hero_title' => ['sometimes', 'string', 'min:1', 'max:140'],
            'data.site.hero_subtitle' => ['sometimes', 'nullable', 'string', 'max:280'],
            'data.site.team_name' => ['sometimes', 'string', 'min:1', 'max:40'],
            'data.site.footer_text' => ['sometimes', 'nullable', 'string', 'max:280'],
            'data.site.footer_links' => ['sometimes', 'array', 'max:10'],
            'data.site.footer_links.*.label' => ['required', 'string', 'max:40'],
            'data.site.footer_links.*.url' => ['required', 'string', 'max:255', 'regex:#^(https?://|/|mailto:)#i'],
            'data.site.contact_url' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^(https?://|mailto:)#i'],
            'data.site.default_board_slug' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:roadmap_boards,slug'],

            'data.branding' => ['sometimes', 'array'],
            'data.branding.primary' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'data.branding.radius' => ['sometimes', 'in:sm,md,lg,xl'],
            'data.branding.font' => ['sometimes', 'in:outfit,system'],
            'data.branding.default_theme' => ['sometimes', 'in:system,light,dark'],
            'data.branding.hero_style' => ['sometimes', 'in:plain,gradient,pattern'],

            'data.features' => ['sometimes', 'array'],
            'data.features.roadmap' => ['sometimes', 'boolean'],
            'data.features.changelog' => ['sometimes', 'boolean'],
            'data.features.comments' => ['sometimes', 'boolean'],
            'data.features.show_vote_counts' => ['sometimes', 'boolean'],
            'data.features.rss' => ['sometimes', 'boolean'],

            'data.moderation' => ['sometimes', 'array'],
            'data.moderation.blocklist' => ['sometimes', 'array', 'max:500'],
            'data.moderation.blocklist.*' => ['string', 'min:1', 'max:60'],
            'data.moderation.max_links_post' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'data.moderation.max_links_comment' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'data.moderation.min_post_seconds' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'data.moderation.min_comment_seconds' => ['sometimes', 'integer', 'min:0', 'max:120'],

            'data.limits' => ['sometimes', 'array'],
            'data.limits.votes_per_visitor_day' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'data.limits.votes_per_ip_day' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'data.limits.new_visitor_votes_day' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'data.limits.posts_per_visitor_day' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'data.limits.posts_per_ip_day' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'data.limits.comments_per_visitor_hour' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'data.limits.tokens_per_ip_day' => ['sometimes', 'integer', 'min:1', 'max:10000'],

            'data.seo' => ['sometimes', 'array'],
            'data.seo.indexable' => ['sometimes', 'boolean'],
            'data.seo.title_suffix' => ['sometimes', 'nullable', 'string', 'max:60'],
            'data.seo.meta_description' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];

        if ($scope === self::GLOBAL_SCOPE) {
            return $all;
        }

        // Board scope: only the overridable keys are validated (the rest is dropped by sanitize()).
        $allowed = ['data' => $all['data']];
        foreach (self::BOARD_OVERRIDABLE as $key) {
            [$section, $field] = explode('.', $key);
            $allowed['data.'.$section] = ['sometimes', 'array'];
            $allowed["data.$key"] = $all["data.$key"];
        }

        return $allowed;
    }

    /**
     * Keep only known keys (and, for board scopes, only overridable ones). Asset
     * paths are never accepted from input. Values are normalised (trim, cast).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<string, mixed>>
     */
    public function sanitize(array $input, string $scope = self::GLOBAL_SCOPE): array
    {
        $out = [];
        $defaults = $this->builtInDefaults();

        foreach ($defaults as $section => $fields) {
            if (! isset($input[$section]) || ! is_array($input[$section])) {
                continue;
            }

            foreach (array_keys($fields) as $field) {
                if (! array_key_exists($field, $input[$section])) {
                    continue;
                }
                if (in_array($field, array_keys(self::ASSET_KEYS), true) && $section === 'branding') {
                    continue;
                }
                if ($scope !== self::GLOBAL_SCOPE && ! in_array("$section.$field", self::BOARD_OVERRIDABLE, true)) {
                    continue;
                }

                $out[$section][$field] = $this->normalise($section, $field, $input[$section][$field], $fields[$field]);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $over @return array<string, mixed> */
    public function deepMerge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value) && ! array_is_list($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function normalise(string $section, string $field, mixed $value, mixed $default): mixed
    {
        if ($section === 'moderation' && $field === 'blocklist') {
            $seen = [];
            foreach ((array) $value as $word) {
                $word = mb_strtolower(trim((string) $word));
                if ($word !== '') {
                    $seen[$word] = true;
                }
            }

            return array_keys($seen);
        }

        if ($section === 'branding' && $field === 'primary') {
            return strtolower((string) $value);
        }

        if ($section === 'site' && $field === 'footer_links') {
            return array_values(array_map(fn ($l) => [
                'label' => trim((string) ($l['label'] ?? '')),
                'url' => trim((string) ($l['url'] ?? '')),
            ], (array) $value));
        }

        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (is_int($default)) {
            return (int) $value;
        }
        if (is_string($default) || $default === null) {
            $value = $value === null ? '' : trim((string) $value);
            if ($value === '' && in_array($field, self::NULLABLE_TEXT, true)) {
                return null;
            }

            return $value;
        }

        return $value;
    }
}
