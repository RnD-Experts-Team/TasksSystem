<?php

namespace App\Services\Roadmap;

use Illuminate\Support\Facades\DB;

/**
 * Denormalised counter maintenance. Portable SQL (correlated sub-selects) so it works on
 * sqlite and MySQL alike.
 *
 *  - votes_count: incremented/decremented transactionally by VoteService; recount* is the repair tool
 *  - comments_count: ALWAYS recomputed (approved comments only), never incremented
 */
class PostCounters
{
    /** @param  int|array<int,int>|null  $postIds  null = every post */
    public function recountVotes(int|array|null $postIds = null): void
    {
        $this->recountPostColumn('votes_count', 'select count(*) from roadmap_votes where roadmap_votes.post_id = roadmap_posts.id', $postIds);
    }

    /** @param  int|array<int,int>|null  $postIds  null = every post */
    public function recountComments(int|array|null $postIds = null): void
    {
        $this->recountPostColumn(
            'comments_count',
            "select count(*) from roadmap_comments where roadmap_comments.post_id = roadmap_posts.id and roadmap_comments.moderation_state = 'approved'",
            $postIds
        );
    }

    /** @param  int|array<int,int>|null  $postIds */
    public function recountPosts(int|array|null $postIds = null): void
    {
        $this->recountVotes($postIds);
        $this->recountComments($postIds);
    }

    /** @param  string|array<int,string>|null  $visitorIds  null = every visitor */
    public function recountVisitors(string|array|null $visitorIds = null): void
    {
        $columns = [
            'posts_count' => 'select count(*) from roadmap_posts where roadmap_posts.visitor_id = roadmap_visitors.id',
            'approved_posts_count' => "select count(*) from roadmap_posts where roadmap_posts.visitor_id = roadmap_visitors.id and roadmap_posts.moderation_state = 'approved'",
            'comments_count' => 'select count(*) from roadmap_comments where roadmap_comments.visitor_id = roadmap_visitors.id',
            'approved_comments_count' => "select count(*) from roadmap_comments where roadmap_comments.visitor_id = roadmap_visitors.id and roadmap_comments.moderation_state = 'approved'",
            'votes_count' => 'select count(*) from roadmap_votes where roadmap_votes.visitor_id = roadmap_visitors.id',
        ];

        foreach (array_chunk($this->normalise($visitorIds) ?? [null], 500) as $chunk) {
            $query = DB::table('roadmap_visitors');
            if ($chunk !== [null]) {
                $query->whereIn('id', $chunk);
            }
            $sets = [];
            foreach ($columns as $column => $sql) {
                $sets[$column] = DB::raw('('.$sql.')');
            }
            $query->update($sets);
        }
    }

    public function recountAll(): void
    {
        $this->recountPosts(null);
        $this->recountVisitors(null);
    }

    /** @param  int|array<int,int>|null  $postIds */
    private function recountPostColumn(string $column, string $subSelect, int|array|null $postIds): void
    {
        foreach (array_chunk($this->normalise($postIds) ?? [null], 500) as $chunk) {
            $query = DB::table('roadmap_posts');
            if ($chunk !== [null]) {
                $query->whereIn('id', $chunk);
            }
            $query->update([$column => DB::raw('('.$subSelect.')')]);
        }
    }

    /** @return array<int,mixed>|null */
    private function normalise(int|string|array|null $ids): ?array
    {
        if ($ids === null) {
            return null;
        }

        return array_values(array_unique(is_array($ids) ? $ids : [$ids]));
    }
}
