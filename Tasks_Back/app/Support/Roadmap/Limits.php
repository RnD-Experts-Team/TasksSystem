<?php

namespace App\Support\Roadmap;

/**
 * Single place for every limit of the public API.
 *
 *  - short-window throttles (RoadmapThrottle): fixed by design, keyed per IP hash and/or visitor
 *  - daily / hourly caps (services, counted from the DB): admin tunable through settings.limits
 */
class Limits
{
    /**
     * Short-window buckets per throttle name.
     *
     * @return array<int, array{0:'ip'|'visitor',1:int,2:int}> [scope, max attempts, decay seconds]
     */
    public static function throttles(string $name): array
    {
        return match ($name) {
            'issue' => [['ip', 5, 60], ['ip', 20, 3600]],
            'read' => [['ip', 240, 60]],
            'suggest' => [['ip', 30, 60]],
            'form-start' => [['visitor', 20, 60]],
            'vote' => [['visitor', 20, 60], ['ip', 60, 60]],
            'post-submit' => [['visitor', 2, 60], ['ip', 10, 3600]],
            'comment' => [['visitor', 3, 60], ['ip', 30, 3600]],
            default => [['ip', 60, 60]],
        };
    }

    /** Durable cap from settings.limits (falls back to config defaults). */
    public static function cap(string $key): int
    {
        $default = (int) config('roadmap.defaults.limits.'.$key, 0);

        return max(0, (int) RuntimeSettings::get('limits.'.$key, $default));
    }

    /** Form token minimum age in seconds. */
    public static function minSeconds(string $kind): int
    {
        $key = $kind === 'comment' ? 'moderation.min_comment_seconds' : 'moderation.min_post_seconds';

        return max(0, (int) RuntimeSettings::get($key, $kind === 'comment' ? 3 : 5));
    }

    public static function maxLinks(string $kind): int
    {
        $key = $kind === 'comment' ? 'moderation.max_links_comment' : 'moderation.max_links_post';

        return max(0, (int) RuntimeSettings::get($key, $kind === 'comment' ? 1 : 2));
    }

    public static function formTokenMaxAge(): int
    {
        return (int) config('roadmap.form_token_max_age', 21600);
    }

    /** @return array<int,string> */
    public static function blocklist(): array
    {
        $list = RuntimeSettings::get('moderation.blocklist', []);

        return is_array($list) ? array_values(array_filter(array_map(
            static fn ($w) => is_string($w) ? trim($w) : '',
            $list
        ), static fn ($w) => $w !== '')) : [];
    }

    public static function teamName(): string
    {
        return (string) RuntimeSettings::get('site.team_name', 'PNE Team');
    }
}
