<?php

namespace App\Services\Roadmap;

use App\Exceptions\RoadmapException;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\SlugRedirect;
use Illuminate\Support\Facades\DB;

/**
 * Merge a duplicate post (source) into another post of the same board (target).
 *
 *  - votes move with insertOrIgnore, so a visitor who voted on both is counted once
 *  - comments move to the target and remember where they came from (original_post_id)
 *  - tags are unioned
 *  - the source is hidden (merged_into_post_id) and a redirect entry is written
 *  - counters of the target and every affected visitor are recomputed
 */
class MergeService
{
    public function __construct(private PostCounters $counters) {}

    /**
     * @return array{votes_moving:int, overlapping_voters:int, comments_moving:int, target_votes_after:int}
     */
    public function preview(Post $source, Post $target): array
    {
        $this->assertMergeable($source, $target);

        $sourceVoters = DB::table('roadmap_votes')->where('post_id', $source->id)->count();
        $overlap = DB::table('roadmap_votes as s')
            ->join('roadmap_votes as t', 't.visitor_id', '=', 's.visitor_id')
            ->where('s.post_id', $source->id)
            ->where('t.post_id', $target->id)
            ->count();

        $movingVotes = $sourceVoters - $overlap;
        $targetVotes = DB::table('roadmap_votes')->where('post_id', $target->id)->count();

        return [
            'votes_moving' => $movingVotes,
            'overlapping_voters' => $overlap,
            'comments_moving' => DB::table('roadmap_comments')->where('post_id', $source->id)->count(),
            'target_votes_after' => $targetVotes + $movingVotes,
        ];
    }

    public function merge(Post $source, Post $target): Post
    {
        $this->assertMergeable($source, $target);

        DB::transaction(function () use ($source, $target) {
            // Lock in id order so two concurrent merges cannot deadlock.
            $ids = [$source->id, $target->id];
            sort($ids);
            Post::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

            // Re-check under the lock.
            $freshSource = Post::query()->findOrFail($source->id);
            $freshTarget = Post::query()->findOrFail($target->id);
            $this->assertMergeable($freshSource, $freshTarget);

            // ── Votes ────────────────────────────────────────────────
            $voterIds = DB::table('roadmap_votes')->where('post_id', $source->id)->pluck('visitor_id')->all();

            DB::table('roadmap_votes')->where('post_id', $source->id)->orderBy('id')->chunkById(500, function ($votes) use ($target) {
                $rows = [];
                foreach ($votes as $vote) {
                    $rows[] = [
                        'post_id' => $target->id,
                        'visitor_id' => $vote->visitor_id,
                        'ip_hash' => $vote->ip_hash,
                        'created_at' => $vote->created_at,
                    ];
                }
                DB::table('roadmap_votes')->insertOrIgnore($rows);
            });
            DB::table('roadmap_votes')->where('post_id', $source->id)->delete();

            // ── Comments ─────────────────────────────────────────────
            Comment::query()->where('post_id', $source->id)->update([
                'post_id' => $target->id,
                'original_post_id' => DB::raw('COALESCE(original_post_id, '.(int) $source->id.')'),
            ]);

            // ── Tags (union) ─────────────────────────────────────────
            $tagIds = DB::table('roadmap_post_tag')->where('post_id', $source->id)->pluck('tag_id')->all();
            foreach ($tagIds as $tagId) {
                DB::table('roadmap_post_tag')->insertOrIgnore(['post_id' => $target->id, 'tag_id' => $tagId]);
            }

            // ── Source: hide, redirect ───────────────────────────────
            Post::query()->where('merged_into_post_id', $source->id)->update(['merged_into_post_id' => $target->id]);
            Post::query()->whereKey($source->id)->update([
                'merged_into_post_id' => $target->id,
                'merged_at' => now(),
                'is_pinned' => false,
                'votes_count' => 0,
                'comments_count' => 0,
            ]);

            SlugRedirect::updateOrCreate(
                ['kind' => 'post', 'old_key' => $source->board_id.':'.$source->number],
                ['target_id' => $target->id, 'created_at' => now()]
            );

            Post::query()->whereKey($target->id)->update(['last_activity_at' => now()]);

            // ── Counters ─────────────────────────────────────────────
            $this->counters->recountPosts([$target->id, $source->id]);
            if ($voterIds) {
                $this->counters->recountVisitors($voterIds);
            }
        });

        return Post::query()->findOrFail($target->id);
    }

    private function assertMergeable(Post $source, Post $target): void
    {
        if ($source->id === $target->id) {
            throw new RoadmapException('A post cannot be merged into itself.', 'merge_self');
        }
        if ($source->board_id !== $target->board_id) {
            throw new RoadmapException('Posts can only be merged within the same board.', 'merge_cross_board');
        }
        if ($source->merged_into_post_id !== null) {
            throw new RoadmapException('This post was already merged into another post.', 'already_merged');
        }
        if ($target->merged_into_post_id !== null) {
            throw new RoadmapException('The target post was merged into another post. Choose the final post instead.', 'target_merged');
        }
    }
}
