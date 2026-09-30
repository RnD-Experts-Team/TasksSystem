<?php

namespace App\Jobs\Roadmap;

use App\Models\Roadmap\AbuseEvent;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use App\Models\Roadmap\Vote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Privacy housekeeping, run by `roadmap:prune` or opportunistically (1 in 500 new visitors).
 *
 *  - nulls IP / UA hashes older than `roadmap.ip_retention_days`
 *  - deletes abuse events older than that window
 *  - deletes visitors that were idle for 180 days and left nothing behind (no votes, posts, comments; never banned ones)
 */
class PruneRoadmapDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const IDLE_VISITOR_DAYS = 180;

    /** @return array<string,int> counts of touched rows */
    public function handle(): array
    {
        $days = max(1, (int) config('roadmap.ip_retention_days', 90));
        $cutoff = now()->subDays($days);

        $counts = [];

        $counts['post_ip_hashes'] = Post::query()->whereNotNull('ip_hash')->where('created_at', '<', $cutoff)->update(['ip_hash' => null]);
        $counts['comment_ip_hashes'] = Comment::query()->whereNotNull('ip_hash')->where('created_at', '<', $cutoff)->update(['ip_hash' => null]);
        $counts['vote_ip_hashes'] = Vote::query()->whereNotNull('ip_hash')->where('created_at', '<', $cutoff)->update(['ip_hash' => null]);

        $counts['visitor_ip_hashes'] = Visitor::query()
            ->where('last_seen_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNotNull('first_ip_hash')->orWhereNotNull('last_ip_hash')->orWhereNotNull('ua_hash'))
            ->update(['first_ip_hash' => null, 'last_ip_hash' => null, 'ua_hash' => null]);

        $counts['abuse_events'] = AbuseEvent::query()->where('created_at', '<', $cutoff)->delete();

        $counts['idle_visitors'] = Visitor::query()
            ->where('is_banned', false)
            ->where('last_seen_at', '<', now()->subDays(self::IDLE_VISITOR_DAYS))
            ->whereDoesntHave('votes')->whereDoesntHave('posts')->whereDoesntHave('comments')
            ->delete();

        return $counts;
    }
}
