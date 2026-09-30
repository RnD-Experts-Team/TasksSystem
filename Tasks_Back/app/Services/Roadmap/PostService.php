<?php

namespace App\Services\Roadmap;

use App\Events\Roadmap\PostApproved;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Status;
use App\Models\Roadmap\StatusChange;
use App\Models\Roadmap\Tag;
use App\Models\Roadmap\Visitor;
use App\Support\Roadmap\RoadmapBusinessException;
use App\Support\Roadmap\RoadmapLimitException;
use App\Support\Roadmap\RoadmapNotFound;
use App\Support\Roadmap\TextSanitizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Public read + submit path for posts.
 *
 * Reads only ever return approved, un-merged posts (plus an owner's own pending view of a single post).
 * Submissions run through the anti-abuse layers documented in the plan (form token, honeypot,
 * shadow ban, blocklist, links, duplicates, durable caps, moderation queue with auto-trust).
 */
class PostService
{
    public function __construct(
        private BoardService $boards,
        private PostSearch $search,
        private FormTokenService $formTokens,
        private AbuseGuardService $abuse,
    ) {}

    // ─── Reads ───────────────────────────────────────────────────

    /**
     * @param  array{q?: ?string, sort?: ?string, status?: array<int,string>, tag?: array<int,string>}  $filters
     */
    public function paginate(Board $board, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = Post::query()
            ->publiclyVisible()
            ->where('roadmap_posts.board_id', $board->id)
            ->with(['status', 'tags']);

        $q = $filters['q'] ?? null;
        if ($q !== null && trim($q) !== '') {
            $this->search->apply($query, $q);
        }

        $statusSlugs = array_values(array_filter((array) ($filters['status'] ?? [])));
        if ($statusSlugs !== []) {
            $query->whereIn('roadmap_posts.status_id', Status::query()->where('board_id', $board->id)->whereIn('slug', $statusSlugs)->select('id'));
        }

        $tagSlugs = array_values(array_filter((array) ($filters['tag'] ?? [])));
        if ($tagSlugs !== []) {
            $query->whereIn('roadmap_posts.id', DB::table('roadmap_post_tag')
                ->whereIn('tag_id', Tag::query()->where('board_id', $board->id)->whereIn('slug', $tagSlugs)->select('id'))
                ->select('post_id'));
        }

        $this->applySort($query, $filters['sort'] ?? 'top');

        return $query->paginate(max(1, min(30, $perPage)), ['*'], 'page', max(1, $page));
    }

    private function applySort(Builder $query, string $sort): void
    {
        $query->orderByDesc('roadmap_posts.is_pinned');

        $since = now()->subDays(7)->toDateTimeString();

        match ($sort) {
            'new' => $query->orderByDesc('roadmap_posts.published_at')->orderByDesc('roadmap_posts.id'),
            'trending' => $query
                ->orderByRaw(
                    '(2 * (select count(*) from roadmap_votes where roadmap_votes.post_id = roadmap_posts.id and roadmap_votes.created_at >= ?)'
                    ." + (select count(*) from roadmap_comments where roadmap_comments.post_id = roadmap_posts.id and roadmap_comments.moderation_state = 'approved' and roadmap_comments.created_at >= ?)) desc",
                    [$since, $since]
                )
                ->orderByDesc('roadmap_posts.votes_count')->orderByDesc('roadmap_posts.id'),
            default => $query->orderByDesc('roadmap_posts.votes_count')->orderByDesc('roadmap_posts.id'),
        };
    }

    /** @return Collection<int,Post> */
    public function similar(Board $board, string $q): Collection
    {
        return $this->search->similar($board, $q, 5);
    }

    /**
     * Post detail. Approved posts are public (also merged ones, which point at their target);
     * a pending/spam post is only visible to the visitor who wrote it (spam looks "pending").
     *
     * @return array{post: Post, history: Collection, changelog: Collection, owner_pending: bool, viewer_state: ?string}
     *
     * @throws RoadmapNotFound
     */
    public function detail(Board $board, int $number, ?Visitor $viewer): array
    {
        $post = Post::query()
            ->where('board_id', $board->id)->where('number', $number)
            ->with(['status', 'tags', 'board:id,slug,name', 'mergedInto.board:id,slug'])
            ->first();

        if (! $post) {
            throw new RoadmapNotFound('Post not found');
        }

        $ownerPending = false;
        $viewerState = null;

        if ($post->moderation_state !== 'approved') {
            $isOwner = $viewer !== null && $post->visitor_id !== null && $post->visitor_id === $viewer->id;
            if (! $isOwner || ! in_array($post->moderation_state, ['pending', 'spam'], true)) {
                throw new RoadmapNotFound('Post not found');
            }
            $ownerPending = true;
            $viewerState = 'pending';
        }

        $history = StatusChange::query()
            ->where('post_id', $post->id)->with(['fromStatus', 'toStatus'])
            ->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (StatusChange $c) => $c->toStatus !== null)->values();

        $changelog = ChangelogEntry::query()->live()
            ->whereIn('id', DB::table('roadmap_changelog_post')->where('post_id', $post->id)->select('changelog_entry_id'))
            ->orderByDesc('published_at')->get();

        return [
            'post' => $post,
            'history' => $history,
            'changelog' => $changelog,
            'owner_pending' => $ownerPending,
            'viewer_state' => $viewerState,
        ];
    }

    /** A publicly visible post (approved, not merged) or 404. */
    public function findVisible(Board $board, int $number): Post
    {
        $post = Post::query()->publiclyVisible()
            ->where('board_id', $board->id)->where('number', $number)->with('status')->first();

        if (! $post) {
            throw new RoadmapNotFound('Post not found');
        }

        return $post;
    }

    // ─── Submit ──────────────────────────────────────────────────

    /**
     * @param  array{title: string, body?: ?string, author_name?: ?string, tag_slugs?: ?array<int,string>, form_token: string}  $data  already sanitised by the FormRequest
     * @return array{number: int, slug: string, moderation_state: string, board_slug: string}
     *
     * @throws RoadmapBusinessException
     * @throws RoadmapLimitException
     */
    public function submit(string $boardSlug, Visitor $visitor, array $data, string $honeypot, string $ipHash): array
    {
        $board = $this->boards->findBySlug($boardSlug);

        // 1. signed, timed, single-use token
        $claims = $this->formTokens->verify($data['form_token'] ?? null, 'post', $board, $visitor);
        $this->formTokens->consume($claims);

        $title = $data['title'];
        $body = $data['body'] ?? null;

        // 2. honeypot: a fake success, nothing stored
        if (trim($honeypot) !== '') {
            $this->abuse->log('honeypot', $visitor->id, $ipHash, $board->id, ['kind' => 'post']);

            return $this->fake($board, $title);
        }

        // 3. shadow ban: a fake success, nothing stored
        if ($visitor->is_banned) {
            $this->abuse->log('banned_write', $visitor->id, $ipHash, $board->id, ['kind' => 'post']);

            return $this->fake($board, $title);
        }

        if (! $board->allow_submissions) {
            throw new RoadmapBusinessException('submissions_closed', 'This board is not accepting new ideas right now.');
        }

        // 4. duplicate content by the same visitor within 24 h
        $hash = TextSanitizer::contentHash($title, (string) $body);
        $duplicate = Post::query()->where('visitor_id', $visitor->id)->where('content_hash', $hash)
            ->where('created_at', '>=', now()->subDay())->exists();
        if ($duplicate) {
            $this->abuse->log('duplicate', $visitor->id, $ipHash, $board->id, ['kind' => 'post']);
            throw new RoadmapBusinessException('duplicate_content', 'You have already submitted this idea.');
        }

        // 5. content rules
        $inspection = $this->abuse->inspectText($title."\n".$body, 'post');
        $flags = [];
        $state = null;

        if ($inspection['spam_term'] !== null) {
            $state = 'spam';
            $flags[] = 'blocklist';
            $this->abuse->log('spam_keyword', $visitor->id, $ipHash, $board->id, ['kind' => 'post']);
        } elseif ($inspection['too_many_links']) {
            $state = 'pending';
            $flags[] = 'links:'.$inspection['links'];
            $this->abuse->log('links', $visitor->id, $ipHash, $board->id, ['kind' => 'post', 'links' => $inspection['links']]);
        } elseif (Post::query()->where('content_hash', $hash)->where('ip_hash', $ipHash)->where('visitor_id', '!=', $visitor->id)->exists()) {
            $state = 'pending';
            $flags[] = 'dup_ip';
            $this->abuse->log('ip_duplicate', $visitor->id, $ipHash, $board->id, ['kind' => 'post']);
        }

        // 6. durable daily caps
        $this->abuse->assertPostCaps($visitor, $ipHash);

        // 7. moderation state
        if ($state === null) {
            $auto = ! $board->require_post_approval || $this->abuse->isTrusted($visitor, $board, 'post');
            $state = $auto ? 'approved' : 'pending';
        }

        $post = DB::transaction(function () use ($board, $visitor, $title, $body, $data, $hash, $ipHash, $state, $flags) {
            $locked = Board::query()->whereKey($board->id)->lockForUpdate()->firstOrFail();
            $number = (int) $locked->next_post_number;
            $locked->update(['next_post_number' => $number + 1]);

            $statusId = Status::query()->where('board_id', $board->id)->where('is_default', true)->value('id')
                ?? Status::query()->where('board_id', $board->id)->orderBy('sort_order')->value('id');
            if ($statusId === null) {
                throw new RoadmapBusinessException('submissions_closed', 'This board is not accepting new ideas right now.');
            }

            $post = Post::create([
                'board_id' => $board->id,
                'number' => $number,
                'slug' => TextSanitizer::slug($title),
                'title' => $title,
                'body' => $body,
                'author_name' => $data['author_name'] ?? null,
                'visitor_id' => $visitor->id,
                'ip_hash' => $ipHash,
                'content_hash' => $hash,
                'moderation_state' => $state,
                'moderated_at' => $state === 'approved' ? now() : null,
                'published_at' => $state === 'approved' ? now() : null,
                'status_id' => $statusId,
                'flags' => $flags === [] ? null : $flags,
                'last_activity_at' => now(),
            ]);

            $tagSlugs = array_slice(array_values(array_unique((array) ($data['tag_slugs'] ?? []))), 0, 3);
            if ($tagSlugs !== []) {
                $tagIds = Tag::query()->where('board_id', $board->id)->whereIn('slug', $tagSlugs)->pluck('id')->all();
                $post->tags()->sync($tagIds);
            }

            Visitor::query()->whereKey($visitor->id)->increment('posts_count');
            if ($state === 'approved') {
                Visitor::query()->whereKey($visitor->id)->increment('approved_posts_count');
            }

            return $post;
        });

        if ($state === 'approved') {
            PostApproved::dispatch($post);
        }

        return [
            'number' => (int) $post->number,
            'slug' => $post->slug,
            // spam is presented as pending: the author must not learn that a filter fired
            'moderation_state' => $state === 'approved' ? 'approved' : 'pending',
            'board_slug' => $board->slug,
        ];
    }

    /** A believable result for honeypot / banned submissions (nothing was stored). */
    private function fake(Board $board, string $title): array
    {
        return [
            'number' => (int) $board->next_post_number,
            'slug' => TextSanitizer::slug($title),
            'moderation_state' => 'pending',
            'board_slug' => $board->slug,
        ];
    }
}
