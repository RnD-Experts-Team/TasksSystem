<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\AbuseEvent;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use App\Models\Roadmap\Vote;
use App\Support\Roadmap\Limits;
use App\Support\Roadmap\RoadmapLimitException;
use App\Support\Roadmap\TextSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Content-level anti-abuse rules shared by the post / comment / vote services.
 *
 * Event types written to roadmap_abuse_events: honeypot, banned_write, rate_limited,
 * spam_keyword, duplicate, links, daily_limit, ip_duplicate.
 */
class AbuseGuardService
{
    public const DAY = 86400;

    public const HOUR = 3600;

    /** @param array<string,mixed> $meta */
    public function log(string $type, ?string $visitorId, ?string $ipHash, ?int $boardId = null, array $meta = []): void
    {
        AbuseEvent::create([
            'type' => $type,
            'visitor_id' => $visitorId,
            'ip_hash' => $ipHash,
            'board_id' => $boardId,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * Inspects visitor text against the moderation settings.
     *
     * @return array{spam_term: ?string, links: int, max_links: int, too_many_links: bool}
     */
    public function inspectText(string $text, string $kind): array
    {
        $links = TextSanitizer::countLinks($text);
        $max = Limits::maxLinks($kind);

        return [
            'spam_term' => TextSanitizer::blockedTerm($text, Limits::blocklist()),
            'links' => $links,
            'max_links' => $max,
            'too_many_links' => $links > $max,
        ];
    }

    /**
     * Auto-trust: not banned, not previously flagged (no rejected/spam content) and either
     * marked trusted by an admin or at least `trust_after_approved` approved items of the same kind.
     */
    public function isTrusted(Visitor $visitor, Board $board, string $kind): bool
    {
        if ($visitor->is_banned) {
            return false;
        }

        $approved = $kind === 'comment' ? $visitor->approved_comments_count : $visitor->approved_posts_count;
        $threshold = $board->trust_after_approved;
        $earned = $visitor->is_trusted || ($threshold !== null && $threshold > 0 && $approved >= $threshold);

        if (! $earned) {
            return false;
        }

        $flagged = Post::query()->where('visitor_id', $visitor->id)->whereIn('moderation_state', ['rejected', 'spam'])->exists()
            || Comment::query()->where('visitor_id', $visitor->id)->whereIn('moderation_state', ['rejected', 'spam'])->exists();

        return ! $flagged;
    }

    // ─── Durable caps (counted from the DB, survive cache clears) ───

    /** @throws RoadmapLimitException */
    public function assertPostCaps(Visitor $visitor, string $ipHash): void
    {
        $since = now()->subSeconds(self::DAY);

        $visitorQuery = Post::query()->where('visitor_id', $visitor->id)->where('created_at', '>=', $since);
        $this->guardCap($visitorQuery, 'created_at', Limits::cap('posts_per_visitor_day'), self::DAY, $visitor, $ipHash);

        $ipQuery = Post::query()->where('ip_hash', $ipHash)->where('created_at', '>=', $since);
        $this->guardCap($ipQuery, 'created_at', Limits::cap('posts_per_ip_day'), self::DAY, $visitor, $ipHash);
    }

    /** @throws RoadmapLimitException */
    public function assertCommentCaps(Visitor $visitor, string $ipHash): void
    {
        $query = Comment::query()->where('visitor_id', $visitor->id)->where('created_at', '>=', now()->subSeconds(self::HOUR));
        $this->guardCap($query, 'created_at', Limits::cap('comments_per_visitor_hour'), self::HOUR, $visitor, $ipHash);
    }

    /** @throws RoadmapLimitException */
    public function assertVoteCaps(Visitor $visitor, string $ipHash): void
    {
        $since = now()->subSeconds(self::DAY);

        $isNew = $visitor->first_seen_at !== null && $visitor->first_seen_at->gt($since);
        $visitorCap = Limits::cap('votes_per_visitor_day');
        if ($isNew) {
            $visitorCap = min($visitorCap, Limits::cap('new_visitor_votes_day'));
        }

        $visitorQuery = Vote::query()->where('visitor_id', $visitor->id)->where('created_at', '>=', $since);
        $this->guardCap($visitorQuery, 'created_at', $visitorCap, self::DAY, $visitor, $ipHash);

        $ipQuery = Vote::query()->where('ip_hash', $ipHash)->where('created_at', '>=', $since);
        $this->guardCap($ipQuery, 'created_at', Limits::cap('votes_per_ip_day'), self::DAY, $visitor, $ipHash);
    }

    /** Count-based cap with a rolling window; retry_after = when the oldest counted row leaves the window. */
    private function guardCap(Builder $query, string $column, int $cap, int $window, Visitor $visitor, string $ipHash): void
    {
        if ($query->count() < $cap) {
            return;
        }

        $oldest = (clone $query)->min($column);
        $retry = $window;
        if ($oldest !== null) {
            $free = Carbon::parse($oldest)->addSeconds($window);
            $retry = max(1, $this->secondsUntil($free));
        }

        $this->log('daily_limit', $visitor->id, $ipHash, null, ['cap' => $cap, 'window' => $window]);

        throw new RoadmapLimitException('daily_limit', 'You have reached the limit for now. Please try again later.', $retry);
    }

    private function secondsUntil(CarbonInterface $at): int
    {
        return (int) max(0, now()->diffInSeconds($at, false));
    }
}
