<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\Setting;
use App\Support\Roadmap\RuntimeSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Reads and writes the roadmap settings (one JSON blob per scope: "global" or "board:{id}").
 *
 *  - Stored data is deep-merged over the defaults (config('roadmap.defaults')).
 *  - Effective values are cached for 60 s and the cache is busted on every write.
 *  - Board scopes may only override hero copy and a few branding keys; everything else falls
 *    back to the global scope.
 *
 * Other services read values with get('limits.votes_per_ip_day') / section('moderation').
 */
class SettingsService
{
    private const CACHE_TTL = 60;

    public function __construct(
        private SettingsSchema $schema,
        private ThemeBuilder $themes,
    ) {}

    // ─── Reading ─────────────────────────────────────────────────────

    /** Effective settings for the global scope (defaults + stored). */
    public function global(): array
    {
        return $this->effective(SettingsSchema::GLOBAL_SCOPE);
    }

    /**
     * Effective settings for a scope ("global" or "board:{id}"). Board scopes are the global
     * settings with that board's allowed overrides applied on top.
     */
    public function forScope(string $scope): array
    {
        return $this->effective($scope);
    }

    public function forBoard(?int $boardId): array
    {
        return $boardId ? $this->effective('board:'.$boardId) : $this->global();
    }

    /** Dotted lookup, e.g. get('limits.votes_per_ip_day', 120). */
    public function get(string $key, mixed $default = null, ?int $boardId = null): mixed
    {
        return Arr::get($this->forBoard($boardId), $key, $default);
    }

    /** @return array<string, mixed> */
    public function section(string $name, ?int $boardId = null): array
    {
        $value = Arr::get($this->forBoard($boardId), $name, []);

        return is_array($value) ? $value : [];
    }

    // ─── Writing ─────────────────────────────────────────────────────

    /**
     * Merge validated input into the stored blob of $scope. Unknown keys are dropped, asset
     * paths are never written from here. Returns the new effective settings.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $scope, array $data): array
    {
        $clean = $this->schema->sanitize($data, $this->normaliseScope($scope));
        $stored = $this->stored($scope);

        $merged = $this->schema->deepMerge($stored, $clean);

        Setting::updateOrCreate(['scope' => $scope], ['data' => $merged]);
        $this->forget($scope);

        return $this->effective($scope);
    }

    /** Set (or clear with null) one branding asset path on the global scope. */
    public function setAsset(string $type, ?string $path): void
    {
        $key = SettingsSchema::ASSET_TYPES[$type] ?? null;
        if ($key === null) {
            throw new \InvalidArgumentException('Unknown asset type.');
        }

        $stored = $this->stored(SettingsSchema::GLOBAL_SCOPE);
        $stored['branding'][$key] = $path;

        Setting::updateOrCreate(['scope' => SettingsSchema::GLOBAL_SCOPE], ['data' => $stored]);
        $this->forgetAll();
    }

    public function assetPath(string $type): ?string
    {
        $key = SettingsSchema::ASSET_TYPES[$type] ?? null;

        return $key ? ($this->global()['branding'][$key] ?? null) : null;
    }

    public function forget(string $scope): void
    {
        RuntimeSettings::flush();
        Cache::forget($this->cacheKey($scope));
        // Board scopes inherit from global, so a global write must also clear board caches.
        if ($scope === SettingsSchema::GLOBAL_SCOPE) {
            $this->forgetAll();
        }
    }

    public function forgetAll(): void
    {
        RuntimeSettings::flush();
        Cache::forget($this->cacheKey(SettingsSchema::GLOBAL_SCOPE));
        foreach (Cache::get('roadmap:settings:scopes', []) as $scope) {
            Cache::forget($this->cacheKey($scope));
        }
    }

    // ─── Admin view ──────────────────────────────────────────────────

    /**
     * Shape of GET /settings (SettingsResponse in the admin types).
     *
     * @return array{scope: string, settings: array, theme: array, assets: array}
     */
    public function adminView(string $scope): array
    {
        $effective = $this->effective($scope);

        return [
            'scope' => $scope,
            'settings' => $this->toAdminShape($effective),
            'theme' => $this->themes->build($effective['branding']),
            'assets' => $this->assets($effective['branding']),
        ];
    }

    /**
     * Same code path the public site uses, applied to unsaved branding (live preview).
     *
     * @param  array<string, mixed>  $branding
     */
    public function themePreview(array $branding): array
    {
        $current = $this->global()['branding'];

        return $this->themes->build(array_replace($current, Arr::only($branding, ['primary', 'radius', 'font', 'default_theme', 'hero_style'])));
    }

    // ─── Public config ───────────────────────────────────────────────

    /**
     * The exact PublicConfig shape of GET /public/roadmap/config.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(?int $boardId = null): array
    {
        $s = $this->forBoard($boardId);
        $global = $this->global();

        $boards = Board::query()
            ->where('is_archived', false)
            ->withCount(['posts as posts_count' => fn ($q) => $q->publiclyVisible()])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Board $b) => [
                'slug' => $b->slug,
                'name' => $b->name,
                'description' => $b->description,
                'icon' => $b->icon,
                'posts_count' => (int) $b->posts_count,
            ])
            ->values()
            ->all();

        return [
            'site' => [
                'name' => $s['site']['name'],
                'tagline' => $s['site']['tagline'] ?: null,
                'hero_title' => $s['site']['hero_title'],
                'hero_subtitle' => $s['site']['hero_subtitle'] ?: null,
                'hero_style' => $s['branding']['hero_style'],
                'team_name' => $s['site']['team_name'],
                'footer_text' => $s['site']['footer_text'] ?: null,
                'footer_links' => array_values($s['site']['footer_links'] ?? []),
                'contact_url' => $s['site']['contact_url'] ?: null,
                'default_board_slug' => $s['site']['default_board_slug'] ?: null,
            ],
            'theme' => $this->themes->build($s['branding']),
            'features' => [
                'roadmap' => (bool) $s['features']['roadmap'],
                'changelog' => (bool) $s['features']['changelog'],
                'comments' => (bool) $s['features']['comments'],
                'show_vote_counts' => (bool) $s['features']['show_vote_counts'],
                'rss' => (bool) $s['features']['rss'],
            ],
            'assets' => $this->assets($s['branding']),
            'boards' => $boards,
            'limits_hint' => $this->limitsHint(),
            'version' => substr(md5(json_encode($global)), 0, 12),
        ];
    }

    /** @return array<string, int> */
    public function limitsHint(): array
    {
        $fallback = [
            'title_min' => 8, 'title_max' => 140, 'body_max' => 5000, 'comment_min' => 2,
            'comment_max' => 2000, 'author_name_max' => 40, 'max_tags' => 3,
        ];
        $configured = config('roadmap.limits_hint');

        return array_map('intval', array_replace($fallback, is_array($configured) ? Arr::only($configured, array_keys($fallback)) : []));
    }

    /** Public URL of a stored asset path (disk "public"), or null. */
    public function assetUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /** @param array<string, mixed> $branding @return array{logo_url:?string,logo_dark_url:?string,favicon_url:?string,og_image_url:?string} */
    public function assets(array $branding): array
    {
        return [
            'logo_url' => $this->assetUrl($branding['logo'] ?? null),
            'logo_dark_url' => $this->assetUrl($branding['logo_dark'] ?? null),
            'favicon_url' => $this->assetUrl($branding['favicon'] ?? null),
            'og_image_url' => $this->assetUrl($branding['og'] ?? null),
        ];
    }

    // ─── Internals ───────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function effective(string $scope): array
    {
        return Cache::remember($this->cacheKey($scope), self::CACHE_TTL, function () use ($scope) {
            $this->rememberScope($scope);
            $base = $this->schema->deepMerge($this->schema->defaults(), $this->stored(SettingsSchema::GLOBAL_SCOPE));

            if ($this->normaliseScope($scope) !== SettingsSchema::GLOBAL_SCOPE) {
                $override = $this->schema->sanitize($this->stored($scope), 'board');
                $base = $this->schema->deepMerge($base, $override);
            }

            return $base;
        });
    }

    /** @return array<string, array<string, mixed>> raw stored blob (or empty) */
    private function stored(string $scope): array
    {
        $row = Setting::query()->where('scope', $scope)->first();
        $data = $row?->data;

        return is_array($data) ? $this->migrateLegacyKeys($data) : [];
    }

    /** Accept the *_path spelling of the asset keys if a blob ever carries it. */
    private function migrateLegacyKeys(array $data): array
    {
        foreach (SettingsSchema::ASSET_KEYS as $storageKey => $outKey) {
            if (isset($data['branding'][$outKey])) {
                $data['branding'][$storageKey] ??= $data['branding'][$outKey];
                unset($data['branding'][$outKey]);
            }
        }

        return $data;
    }

    private function normaliseScope(string $scope): string
    {
        return $scope === SettingsSchema::GLOBAL_SCOPE ? SettingsSchema::GLOBAL_SCOPE : 'board';
    }

    private function cacheKey(string $scope): string
    {
        return 'roadmap:settings:'.$scope;
    }

    /** Track which board scopes are cached so a global write can clear them. */
    private function rememberScope(string $scope): void
    {
        if ($scope === SettingsSchema::GLOBAL_SCOPE) {
            return;
        }
        $scopes = Cache::get('roadmap:settings:scopes', []);
        if (! in_array($scope, $scopes, true)) {
            $scopes[] = $scope;
            Cache::put('roadmap:settings:scopes', array_slice($scopes, -200), 3600);
        }
    }

    /**
     * Storage keys -> the TypeScript RoadmapSettings shape (asset keys renamed to *_path).
     *
     * @param  array<string, array<string, mixed>>  $s
     * @return array<string, array<string, mixed>>
     */
    private function toAdminShape(array $s): array
    {
        foreach (SettingsSchema::ASSET_KEYS as $storageKey => $outKey) {
            $s['branding'][$outKey] = $s['branding'][$storageKey] ?? null;
            unset($s['branding'][$storageKey]);
        }

        return $s;
    }
}
