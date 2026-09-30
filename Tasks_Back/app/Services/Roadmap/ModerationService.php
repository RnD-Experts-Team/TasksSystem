<?php

namespace App\Services\Roadmap;

use App\Events\Roadmap\CommentApproved;
use App\Events\Roadmap\PostApproved;
use App\Exceptions\RoadmapException;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use App\Models\Roadmap\Vote;
use App\Models\User;
use App\Support\Roadmap\TextSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Moderation of posts and comments, visitor bans and bulk abuse removal.
 *
 * Content removal is soft (moderation_state = spam) so it stays reviewable and reversible;
 * votes are hard deleted and every affected counter is recomputed afterwards.
 */
class ModerationService
{
    private const STATES = ['pending', 'approved', 'rejected', 'spam'];

    public function __construct(
        private PostCounters $counters,
        private SettingsService $settings,
    ) {}

    // ─── Posts ───────────────────────────────────────────────────────

    public function moderatePost(Post $post, string $state, ?string $reason, User $by): Post
    {
        $this->assertState($state);

        $becameApproved = DB::transaction(function () use ($post, $state, $reason, $by) {
            $post = Post::query()->lockForUpdate()->findOrFail($post->id);
            $wasApproved = $post->moderation_state === 'approved';

            $post->moderation_state = $state;
            $post->moderated_at = now();
            $post->moderated_by = $by->id;
            $post->moderation_reason = $reason !== null && $reason !== '' ? mb_substr($reason, 0, 200) : null;

            if ($state === 'approved') {
                $post->published_at ??= now();
                $post->last_activity_at = now();
            }
            $post->save();

            if ($post->visitor_id) {
                $this->counters->recountVisitors($post->visitor_id);
                if ($state === 'approved') {
                    $this->markTrustedIfEligible($post->visitor_id, $post->board_id);
                }
            }

            return $state === 'approved' && ! $wasApproved;
        });

        $post = $post->fresh();
        if ($becameApproved) {
            event(new PostApproved($post));
        }

        return $post;
    }

    /**
     * @param  int[]  $ids
     * @return int number of posts updated
     */
    public function bulkModeratePosts(array $ids, string $state, User $by): int
    {
        $this->assertState($state);

        $done = 0;
        foreach (Post::query()->whereIn('id', array_unique($ids))->get() as $post) {
            $this->moderatePost($post, $state, null, $by);
            $done++;
        }

        return $done;
    }

    // ─── Comments ────────────────────────────────────────────────────

    public function moderateComment(Comment $comment, string $state, ?string $reason, User $by): Comment
    {
        $this->assertState($state);

        $becameApproved = DB::transaction(function () use ($comment, $state) {
            $comment = Comment::query()->lockForUpdate()->findOrFail($comment->id);
            $wasApproved = $comment->moderation_state === 'approved';

            $comment->moderation_state = $state;
            $comment->moderated_at = now();
            $comment->save();

            $this->counters->recountComments($comment->post_id);
            if ($comment->visitor_id) {
                $this->counters->recountVisitors($comment->visitor_id);
                if ($state === 'approved') {
                    $boardId = Post::query()->whereKey($comment->post_id)->value('board_id');
                    $this->markTrustedIfEligible($comment->visitor_id, (int) $boardId, comments: true);
                }
            }
            if ($state === 'approved') {
                Post::query()->whereKey($comment->post_id)->update(['last_activity_at' => now()]);
            }

            return $state === 'approved' && ! $wasApproved;
        });

        $comment = $comment->fresh();
        if ($becameApproved) {
            event(new CommentApproved($comment));
        }

        return $comment;
    }

    /**
     * @param  int[]  $ids
     */
    public function bulkModerateComments(array $ids, string $state, User $by): int
    {
        $done = 0;
        foreach (Comment::query()->whereIn('id', array_unique($ids))->get() as $comment) {
            $this->moderateComment($comment, $state, null, $by);
            $done++;
        }

        return $done;
    }

    public function deleteComment(Comment $comment): void
    {
        DB::transaction(function () use ($comment) {
            $postId = $comment->post_id;
            $visitorId = $comment->visitor_id;
            $comment->delete(); // replies cascade
            $this->counters->recountComments($postId);
            if ($visitorId) {
                $this->counters->recountVisitors($visitorId);
            }
        });
    }

    /** A comment written by the team (approved immediately, shown publicly as the team). */
    public function replyAsTeam(Post $post, string $body, ?int $parentId, User $by): Comment
    {
        $body = TextSanitizer::clean($body, true);
        if ($body === '') {
            throw new RoadmapException('The reply cannot be empty.', 'empty_reply');
        }

        if ($parentId !== null) {
            $parent = Comment::query()->where('post_id', $post->id)->find($parentId);
            if (! $parent || $parent->parent_id !== null) {
                throw new RoadmapException('Replies can only be added to a top-level comment of this post.', 'invalid_parent');
            }
        }

        $comment = DB::transaction(function () use ($post, $body, $parentId, $by) {
            $comment = Comment::create([
                'post_id' => $post->id,
                'parent_id' => $parentId,
                'visitor_id' => null,
                'user_id' => $by->id,
                'is_admin' => true,
                'author_name' => mb_substr((string) $by->name, 0, 40),
                'body' => $body,
                'moderation_state' => 'approved',
                'moderated_at' => now(),
                'content_hash' => TextSanitizer::contentHash($body),
            ]);

            $this->counters->recountComments($post->id);
            Post::query()->whereKey($post->id)->update(['last_activity_at' => now()]);

            return $comment;
        });

        event(new CommentApproved($comment));

        return $comment;
    }

    // ─── Visitors ────────────────────────────────────────────────────

    /**
     * @param  array{q?:?string,banned?:mixed,trusted?:mixed,sort?:?string,per_page?:mixed,page?:mixed}  $filters
     */
    public function visitors(array $filters): LengthAwarePaginator
    {
        $query = Visitor::query();

        if (! empty($filters['q'])) {
            $q = (string) $filters['q'];
            $query->where(function (Builder $w) use ($q) {
                $like = $this->escapeLike($q).'%';
                $w->where('id', $q)->orWhere('first_ip_hash', 'like', $like)->orWhere('last_ip_hash', 'like', $like);
            });
        }
        if (isset($filters['banned']) && $filters['banned'] !== null && $filters['banned'] !== '') {
            $query->where('is_banned', filter_var($filters['banned'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($filters['trusted']) && $filters['trusted'] !== null && $filters['trusted'] !== '') {
            $query->where('is_trusted', filter_var($filters['trusted'], FILTER_VALIDATE_BOOLEAN));
        }

        match ($filters['sort'] ?? 'last_seen') {
            'votes' => $query->orderByDesc('votes_count'),
            'posts' => $query->orderByDesc('posts_count'),
            'first_seen' => $query->orderByDesc('first_seen_at'),
            default => $query->orderByDesc('last_seen_at'),
        };

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        return $query->orderBy('id')->paginate($perPage);
    }

    public function ban(Visitor $visitor, ?string $reason, bool $removeContent, bool $removeVotes, User $by): array
    {
        return DB::transaction(function () use ($visitor, $reason, $removeContent, $removeVotes, $by) {
            $this->banVisitors([$visitor->id], $reason, $by);

            $remove = [];
            if ($removeContent) {
                array_push($remove, 'posts', 'comments');
            }
            if ($removeVotes) {
                $remove[] = 'votes';
            }

            $result = $remove
                ? $this->removeContent('visitor', $visitor->id, $remove, [$visitor->id])
                : ['votes_removed' => 0, 'posts_removed' => 0, 'comments_removed' => 0];

            return $result + ['visitors_banned' => 1];
        });
    }

    public function unban(Visitor $visitor): Visitor
    {
        $visitor->forceFill(['is_banned' => false, 'banned_reason' => null, 'banned_at' => null, 'banned_by' => null])->save();

        return $visitor->fresh();
    }

    // ─── Bulk abuse removal ──────────────────────────────────────────

    /**
     * Remove everything a visitor (or an IP hash) contributed, in ONE transaction, recount
     * every affected counter and optionally ban the involved visitors.
     *
     * @param  'visitor'|'ip_hash'  $by
     * @param  array<int,string>  $remove  any of votes|posts|comments
     * @return array{votes_removed:int,posts_removed:int,comments_removed:int,visitors_banned:int}
     */
    public function bulkRemove(string $by, string $value, array $remove, bool $ban, ?string $reason, User $admin): array
    {
        return DB::transaction(function () use ($by, $value, $remove, $ban, $reason, $admin) {
            $visitorIds = $this->involvedVisitors($by, $value);

            $result = $this->removeContent($by, $value, $remove, $visitorIds);

            $banned = 0;
            if ($ban && $visitorIds) {
                $banned = $this->banVisitors($visitorIds, $reason, $admin);
            }

            return $result + ['visitors_banned' => $banned];
        });
    }

    // ─── Internals ───────────────────────────────────────────────────

    /**
     * @param  array<int,string>  $remove
     * @param  array<int,string>  $visitorIds
     * @return array{votes_removed:int,posts_removed:int,comments_removed:int}
     */
    private function removeContent(string $by, string $value, array $remove, array $visitorIds): array
    {
        $scope = fn (Builder|\Illuminate\Database\Query\Builder $q) => $by === 'ip_hash'
            ? $q->where('ip_hash', $value)
            : $q->where('visitor_id', $value);

        $affectedPosts = [];
        $affectedVisitors = $visitorIds;
        $result = ['votes_removed' => 0, 'posts_removed' => 0, 'comments_removed' => 0];

        if (in_array('votes', $remove, true)) {
            $votes = $scope(Vote::query());
            $affectedPosts = array_merge($affectedPosts, (clone $votes)->pluck('post_id')->all());
            $affectedVisitors = array_merge($affectedVisitors, (clone $votes)->pluck('visitor_id')->all());
            $result['votes_removed'] = $scope(Vote::query())->delete();
        }

        if (in_array('posts', $remove, true)) {
            $posts = $scope(Post::query())->where('moderation_state', '!=', 'spam');
            $affectedPosts = array_merge($affectedPosts, (clone $posts)->pluck('id')->all());
            $affectedVisitors = array_merge($affectedVisitors, (clone $posts)->whereNotNull('visitor_id')->pluck('visitor_id')->all());
            $result['posts_removed'] = $scope(Post::query())->where('moderation_state', '!=', 'spam')->update([
                'moderation_state' => 'spam',
                'moderated_at' => now(),
                'moderation_reason' => 'Bulk removal',
            ]);
        }

        if (in_array('comments', $remove, true)) {
            $comments = $scope(Comment::query())->where('moderation_state', '!=', 'spam');
            $affectedPosts = array_merge($affectedPosts, (clone $comments)->pluck('post_id')->all());
            $affectedVisitors = array_merge($affectedVisitors, (clone $comments)->whereNotNull('visitor_id')->pluck('visitor_id')->all());
            $result['comments_removed'] = $scope(Comment::query())->where('moderation_state', '!=', 'spam')->update([
                'moderation_state' => 'spam',
                'moderated_at' => now(),
            ]);
        }

        if ($affectedPosts) {
            $this->counters->recountPosts(array_values(array_unique($affectedPosts)));
        }
        if ($affectedVisitors) {
            $this->counters->recountVisitors(array_values(array_unique($affectedVisitors)));
        }

        return $result;
    }

    /** @return array<int,string> visitor ids tied to the subject (the visitor itself, or everyone seen behind an ip hash) */
    private function involvedVisitors(string $by, string $value): array
    {
        if ($by === 'visitor') {
            return Visitor::query()->whereKey($value)->pluck('id')->all();
        }

        $ids = Visitor::query()
            ->where(fn ($w) => $w->where('first_ip_hash', $value)->orWhere('last_ip_hash', $value))
            ->pluck('id');

        $fromRows = collect([
            Vote::query()->where('ip_hash', $value)->pluck('visitor_id'),
            Post::query()->where('ip_hash', $value)->whereNotNull('visitor_id')->pluck('visitor_id'),
            Comment::query()->where('ip_hash', $value)->whereNotNull('visitor_id')->pluck('visitor_id'),
        ])->flatten();

        return $ids->merge($fromRows)->unique()->values()->all();
    }

    /** @param array<int,string> $ids @return int visitors newly banned */
    private function banVisitors(array $ids, ?string $reason, User $by): int
    {
        return Visitor::query()->whereIn('id', $ids)->where('is_banned', false)->update([
            'is_banned' => true,
            'banned_reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 200) : null,
            'banned_at' => now(),
            'banned_by' => $by->id,
        ]);
    }

    private function markTrustedIfEligible(string $visitorId, int $boardId, bool $comments = false): void
    {
        $threshold = Board::query()->whereKey($boardId)->value('trust_after_approved');
        if (! $threshold) {
            return;
        }

        $visitor = Visitor::query()->find($visitorId);
        if (! $visitor || $visitor->is_banned || $visitor->is_trusted) {
            return;
        }

        $count = $comments ? $visitor->approved_comments_count : $visitor->approved_posts_count;
        if ($count >= $threshold) {
            $visitor->forceFill(['is_trusted' => true])->save();
        }
    }

    private function assertState(string $state): void
    {
        if (! in_array($state, self::STATES, true)) {
            throw new RoadmapException('Unknown moderation state.', 'invalid_state');
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
