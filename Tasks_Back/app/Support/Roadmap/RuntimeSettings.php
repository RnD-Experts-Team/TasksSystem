<?php

namespace App\Support\Roadmap;

use App\Models\Roadmap\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Minimal read-only access to the GLOBAL roadmap settings for the write path
 * (limits, moderation rules, team name). Stored JSON is deep-merged over
 * `config('roadmap.defaults')`. Cached 60 s (not cached in the testing env).
 *
 * The admin SettingsService should call {@see RuntimeSettings::flush()} after writing.
 */
class RuntimeSettings
{
    public const CACHE_KEY = 'roadmap.runtime_settings.global';

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $load = function (): array {
            $defaults = (array) config('roadmap.defaults', []);
            try {
                $stored = Setting::query()->where('scope', 'global')->value('data');
            } catch (Throwable) {
                $stored = null;
            }

            return self::merge($defaults, is_array($stored) ? $stored : []);
        };

        if (app()->environment('testing')) {
            return $load();
        }

        return Cache::remember(self::CACHE_KEY, 60, $load);
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        return Arr::get(self::all(), $path, $default);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Recursive merge where scalars/lists in $over replace $base, assoc arrays merge. */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && Arr::isAssoc($value) && Arr::isAssoc($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
