<?php

namespace App\Http\Resources\Roadmap\Admin;

use App\Models\Roadmap\Status;
use App\Models\Roadmap\Tag;
use App\Models\Roadmap\Visitor;
use Carbon\CarbonInterface;

/** Shared, hand-listed building blocks of the admin resources (never `toArray()` a model). */
final class AdminSupport
{
    public static function iso(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601ZuluString();
    }

    public static function status(?Status $s): ?array
    {
        return $s ? [
            'id' => $s->id,
            'slug' => $s->slug,
            'name' => $s->name,
            'color' => $s->color,
            'kind' => $s->kind,
        ] : null;
    }

    public static function tag(Tag $t): array
    {
        return ['id' => $t->id, 'slug' => $t->slug, 'name' => $t->name, 'color' => $t->color];
    }

    public static function shortHash(?string $hash): ?string
    {
        return $hash ? substr($hash, 0, 8) : null;
    }

    /** The hash shown for a visitor: the most recent one. */
    public static function visitorIp(Visitor $v): ?string
    {
        return $v->last_ip_hash ?: $v->first_ip_hash;
    }

    public static function visitorRef(?Visitor $v): ?array
    {
        return $v ? [
            'id' => $v->id,
            'is_banned' => (bool) $v->is_banned,
            'is_trusted' => (bool) $v->is_trusted,
            'ip_hash_short' => self::shortHash(self::visitorIp($v)),
            'approved_posts_count' => (int) $v->approved_posts_count,
        ] : null;
    }

    /** Flags are stored as a list of strings ("links:3"); tolerate an assoc array too. @return string[] */
    public static function flags(mixed $flags): array
    {
        if (! is_array($flags)) {
            return [];
        }

        $out = [];
        foreach ($flags as $key => $value) {
            $out[] = is_int($key) ? (string) $value : $key.':'.(is_scalar($value) ? $value : json_encode($value));
        }

        return array_values($out);
    }
}
