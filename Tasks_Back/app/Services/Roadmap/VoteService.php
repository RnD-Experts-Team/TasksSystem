<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use App\Models\Roadmap\Vote;
use App\Support\Roadmap\RoadmapBusinessException;
use App\Support\Roadmap\RoadmapLimitException;
use App\Support\Roadmap\RoadmapNotFound;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent "set desired vote state". votes_count is maintained transactionally:
 * +1 only when a row was really inserted, -1 only when a row was really deleted.
 */
class VoteService
{
    public function __construct(
        private BoardService $boards,
        private PostService $posts,
        private AbuseGuardService $abuse,
    ) {}

    /**
     * @return array{voted: bool, votes_count: int}
     *
     * @throws RoadmapNotFound
     * @throws RoadmapBusinessException voting_closed
     * @throws RoadmapLimitException daily caps
     */
    public function set(string $boardSlug, int $number, Visitor $visitor, bool $voted, string $ipHash): array
    {
        $board = $this->boards->findBySlug($boardSlug);
        $post = $this->posts->findVisible($board, $number);

        if (! $board->allow_votes || ($post->status && $post->status->locks_voting)) {
            throw new RoadmapBusinessException('voting_closed', 'Voting is closed for this idea.');
        }

        // shadow ban: look successful, change nothing
        if ($visitor->is_banned) {
            $this->abuse->log('banned_write', $visitor->id, $ipHash, $board->id, ['kind' => 'vote']);

            return ['voted' => $voted, 'votes_count' => (int) $post->votes_count];
        }

        if ($voted) {
            $already = Vote::query()->where('post_id', $post->id)->where('visitor_id', $visitor->id)->exists();
            if (! $already) {
                $this->abuse->assertVoteCaps($visitor, $ipHash);
                $this->cast($post, $visitor, $ipHash);
            }
        } else {
            $this->retract($post, $visitor);
        }

        return [
            'voted' => Vote::query()->where('post_id', $post->id)->where('visitor_id', $visitor->id)->exists(),
            'votes_count' => (int) Post::query()->whereKey($post->id)->value('votes_count'),
        ];
    }

    private function cast(Post $post, Visitor $visitor, string $ipHash): void
    {
        DB::transaction(function () use ($post, $visitor, $ipHash) {
            // INSERT IGNORE / INSERT OR IGNORE: a concurrent duplicate is a silent no-op
            $inserted = DB::table('roadmap_votes')->insertOrIgnore([
                'post_id' => $post->id,
                'visitor_id' => $visitor->id,
                'ip_hash' => $ipHash,
                'created_at' => now(),
            ]);

            if ($inserted > 0) {
                Post::query()->whereKey($post->id)->increment('votes_count');
                Visitor::query()->whereKey($visitor->id)->increment('votes_count');
            }
        });
    }

    private function retract(Post $post, Visitor $visitor): void
    {
        DB::transaction(function () use ($post, $visitor) {
            $deleted = Vote::query()->where('post_id', $post->id)->where('visitor_id', $visitor->id)->delete();

            if ($deleted > 0) {
                Post::query()->whereKey($post->id)->where('votes_count', '>', 0)->decrement('votes_count');
                Visitor::query()->whereKey($visitor->id)->where('votes_count', '>', 0)->decrement('votes_count');
            }
        });
    }
}
