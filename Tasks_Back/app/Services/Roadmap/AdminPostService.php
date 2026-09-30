<?php

namespace App\Services\Roadmap;

use App\Events\Roadmap\PostApproved;
use App\Events\Roadmap\PostStatusChanged;
use App\Exceptions\RoadmapException;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\SlugRedirect;
use App\Models\Roadmap\Status;
use App\Models\Roadmap\StatusChange;
use App\Models\Roadmap\Tag;
use App\Models\User;
use App\Support\Roadmap\TextSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side operations on posts (everything except moderation and merging, which have
 * their own services): listing, detail data, create/edit/delete, status changes, official
 * response, pinning, moving between boards, tags and the roadmap kanban.
 */
class AdminPostService
{
    public function __construct(
        private MarkdownRenderer $markdown,
        private PostCounters $counters,
    ) {}

    // ─── Reading ─────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->listQuery()
            ->when(! empty($filters['board_id']), fn (Builder $q) => $q->where('board_id', (int) $filters['board_id']))
            ->when(! empty($filters['status_id']), fn (Builder $q) => $q->where('status_id', (int) $filters['status_id']))
            ->when(! empty($filters['visitor_id']), fn (Builder $q) => $q->where('visitor_id', $filters['visitor_id']))
            ->when(! empty($filters['ip_hash']), fn (Builder $q) => $q->where('ip_hash', $filters['ip_hash']))
            ->when(! empty($filters['tag_id']), fn (Builder $q) => $q->whereHas('tags', fn ($t) => $t->where('roadmap_tags.id', (int) $filters['tag_id'])));

        $state = $filters['moderation_state'] ?? 'all';
        if ($state !== 'all') {
            $query->where('moderation_state', $state);
        }

        if (! empty($filters['q'])) {
            $tokens = array_slice(preg_split('/\s+/u', trim((string) $filters['q'])) ?: [], 0, 6);
            foreach ($tokens as $token) {
                $like = '%'.$this->escapeLike($token).'%';
                $query->where(fn (Builder $w) => $w->where('title', 'like', $like)->orWhere('body', 'like', $like));
            }
        }

        match ($filters['sort'] ?? 'new') {
            'votes' => $query->orderByDesc('votes_count')->orderByDesc('id'),
            'activity' => $query->orderByDesc('last_activity_at')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));

        return $query->paginate($perPage);
    }

    public function findForList(int $id): ?Post
    {
        return $this->listQuery()->find($id);
    }

    /** Post with everything the detail sheet needs. */
    public function findDetailed(int $id): ?Post
    {
        $post = $this->listQuery()->find($id);
        if (! $post) {
            return null;
        }

        $post->load(['statusChanges.fromStatus', 'statusChanges.toStatus']);
        $post->setRelation('mergedFrom', Post::query()->where('merged_into_post_id', $post->id)->orderBy('number')->get(['id', 'number', 'title']));

        $names = User::query()
            ->whereIn('id', $post->statusChanges->pluck('changed_by')->filter()->unique()->all())
            ->pluck('name', 'id');
        $post->setRelation('changerNames', collect($names));

        $post->setRelation('votesByIp', collect(
            DB::table('roadmap_votes')
                ->selectRaw('SUBSTR(ip_hash, 1, 8) as ip_short, COUNT(*) as votes')
                ->where('post_id', $post->id)
                ->whereNotNull('ip_hash')
                ->groupByRaw('SUBSTR(ip_hash, 1, 8)')
                ->orderByDesc('votes')
                ->limit(20)
                ->get()
        ));

        return $post;
    }

    // ─── Create / edit / delete ──────────────────────────────────────

    public function create(array $data, User $by): Post
    {
        $board = Board::query()->findOrFail($data['board_id']);

        $title = TextSanitizer::clean($data['title']);
        $body = TextSanitizer::clean($data['body'] ?? null, true);

        $post = DB::transaction(function () use ($board, $data, $title, $body, $by) {
            $status = $this->resolveStatus($board, $data['status_id'] ?? null);

            $locked = Board::query()->lockForUpdate()->findOrFail($board->id);
            $number = $locked->next_post_number;
            $locked->next_post_number = $number + 1;
            $locked->save();

            $post = Post::create([
                'board_id' => $board->id,
                'number' => $number,
                'slug' => TextSanitizer::slug($title),
                'title' => $title,
                'body' => $body !== '' ? $body : null,
                'created_by_user_id' => $by->id,
                'content_hash' => TextSanitizer::contentHash($title, $body),
                'moderation_state' => 'approved',
                'moderated_at' => now(),
                'moderated_by' => $by->id,
                'published_at' => now(),
                'status_id' => $status->id,
                'last_activity_at' => now(),
            ]);

            $tagIds = $this->boardTagIds($board->id, $data['tag_ids'] ?? []);
            if ($tagIds) {
                $post->tags()->sync($tagIds);
            }

            return $post;
        });

        event(new PostApproved($post));

        return $this->findForList($post->id);
    }

    public function update(Post $post, array $data): Post
    {
        if (array_key_exists('title', $data)) {
            $post->title = TextSanitizer::clean($data['title']);
            $post->slug = TextSanitizer::slug($post->title);
        }
        if (array_key_exists('body', $data)) {
            $body = TextSanitizer::clean($data['body'], true);
            $post->body = $body !== '' ? $body : null;
        }
        if (array_key_exists('author_name', $data)) {
            $name = TextSanitizer::clean($data['author_name']);
            $post->author_name = $name !== '' ? mb_substr($name, 0, 40) : null;
        }
        $post->content_hash = TextSanitizer::contentHash($post->title, (string) $post->body);
        $post->save();

        return $this->findForList($post->id);
    }

    public function delete(Post $post): void
    {
        DB::transaction(function () use ($post) {
            $visitorIds = collect([$post->visitor_id])
                ->merge(DB::table('roadmap_votes')->where('post_id', $post->id)->pluck('visitor_id'))
                ->merge(DB::table('roadmap_comments')->where('post_id', $post->id)->whereNotNull('visitor_id')->pluck('visitor_id'))
                ->filter()->unique()->values()->all();

            SlugRedirect::query()->where('kind', 'post')->where('target_id', $post->id)->delete();
            $post->delete();

            if ($visitorIds) {
                $this->counters->recountVisitors($visitorIds);
            }
        });
    }

    // ─── Status / response / pin ─────────────────────────────────────

    public function changeStatus(Post $post, int $statusId, ?string $note, bool $noteIsPublic, User $by): Post
    {
        $to = Status::query()->where('board_id', $post->board_id)->find($statusId);
        if (! $to) {
            throw new RoadmapException('The status does not belong to this post\'s board.', 'invalid_status');
        }
        if ($post->status_id === $to->id) {
            throw new RoadmapException('The post already has this status.', 'status_unchanged');
        }

        $from = $post->status_id;
        DB::transaction(function () use ($post, $to, $from, $note, $noteIsPublic, $by) {
            $this->applyStatus($post, $to, $from, $note, $noteIsPublic, $by->id);
        });

        event(new PostStatusChanged($post->fresh(), $from, $to->id));

        return $this->findForList($post->id);
    }

    public function setResponse(Post $post, string $markdown, User $by): Post
    {
        $markdown = trim($markdown);
        $post->response_md = $markdown;
        $post->response_html = $this->markdown->render($markdown);
        $post->responded_at = now();
        $post->responded_by = $by->id;
        $post->last_activity_at = now();
        $post->save();

        return $this->findForList($post->id);
    }

    public function clearResponse(Post $post): Post
    {
        $post->forceFill([
            'response_md' => null,
            'response_html' => null,
            'responded_at' => null,
            'responded_by' => null,
        ])->save();

        return $this->findForList($post->id);
    }

    public function pin(Post $post, ?bool $pinned): Post
    {
        $post->is_pinned = $pinned ?? ! $post->is_pinned;
        $post->save();

        return $this->findForList($post->id);
    }

    // ─── Tags ────────────────────────────────────────────────────────

    /** @param  int[]  $tagIds */
    public function syncTags(Post $post, array $tagIds): Post
    {
        $valid = $this->boardTagIds($post->board_id, $tagIds);
        if (count($valid) !== count(array_unique($tagIds))) {
            throw new RoadmapException('Some tags do not belong to this post\'s board.', 'invalid_tags');
        }

        $post->tags()->sync($valid);

        return $this->findForList($post->id);
    }

    // ─── Move to another board ───────────────────────────────────────

    public function move(Post $post, int $boardId, ?int $statusId): Post
    {
        if ($post->merged_into_post_id !== null) {
            throw new RoadmapException('A merged post cannot be moved.', 'post_merged');
        }
        if ($post->board_id === $boardId) {
            throw new RoadmapException('The post is already on this board.', 'same_board');
        }
        if (Post::query()->where('merged_into_post_id', $post->id)->exists()) {
            throw new RoadmapException('Posts were merged into this post. Move is not available for it.', 'has_merged_posts');
        }

        $target = Board::query()->findOrFail($boardId);

        DB::transaction(function () use ($post, $target, $statusId) {
            $status = $this->resolveStatus($target, $statusId);
            $oldBoardId = $post->board_id;
            $oldNumber = $post->number;
            $oldStatusId = $post->status_id;

            $locked = Board::query()->lockForUpdate()->findOrFail($target->id);
            $number = $locked->next_post_number;
            $locked->next_post_number = $number + 1;
            $locked->save();

            // Old links keep working: /roadmap/{old-board}/p/{old-number} -> this post.
            SlugRedirect::updateOrCreate(
                ['kind' => 'post', 'old_key' => $oldBoardId.':'.$oldNumber],
                ['target_id' => $post->id, 'created_at' => now()]
            );

            // Tags are remapped by name; unknown ones are dropped.
            $names = $post->tags()->pluck('roadmap_tags.name')->map(fn ($n) => mb_strtolower($n))->all();
            $newTagIds = $names
                ? Tag::query()->where('board_id', $target->id)->get()->filter(fn (Tag $t) => in_array(mb_strtolower($t->name), $names, true))->pluck('id')->all()
                : [];

            $post->board_id = $target->id;
            $post->number = $number;
            $post->status_id = $status->id;
            $post->roadmap_order = 0;
            $post->is_pinned = false;
            $post->last_activity_at = now();
            $post->save();

            $post->tags()->sync($newTagIds);

            StatusChange::create([
                'post_id' => $post->id,
                'from_status_id' => $oldStatusId,
                'to_status_id' => $status->id,
                'changed_by' => null,
                'note' => 'Moved to '.$target->name,
                'is_public' => false,
                'created_at' => now(),
            ]);
        });

        return $this->findForList($post->id);
    }

    // ─── Roadmap kanban ──────────────────────────────────────────────

    /**
     * @return array{board: Board, columns: array<int, array{status: Status, posts: Collection}>}
     */
    public function roadmap(Board $board): array
    {
        $statuses = Status::query()->where('board_id', $board->id)->where('is_roadmap_column', true)->orderBy('sort_order')->orderBy('id')->get();

        $posts = Post::query()
            ->with('tags')
            ->publiclyVisible()
            ->where('board_id', $board->id)
            ->whereIn('status_id', $statuses->pluck('id'))
            ->orderBy('roadmap_order')
            ->orderByDesc('votes_count')
            ->orderBy('id')
            ->get()
            ->groupBy('status_id');

        return [
            'board' => $board,
            'columns' => $statuses->map(fn (Status $s) => [
                'status' => $s,
                'posts' => $posts->get($s->id, collect()),
            ])->all(),
        ];
    }

    /** @param  int[]  $orderedIds */
    public function roadmapMove(Board $board, int $postId, int $toStatusId, array $orderedIds, User $by): array
    {
        $post = Post::query()->publiclyVisible()->where('board_id', $board->id)->find($postId);
        if (! $post) {
            throw new RoadmapException('Post not found on this board.', 'post_not_found', 404);
        }
        $to = Status::query()->where('board_id', $board->id)->find($toStatusId);
        if (! $to) {
            throw new RoadmapException('The status does not belong to this board.', 'invalid_status');
        }

        $from = $post->status_id;
        DB::transaction(function () use ($board, $post, $to, $from, $orderedIds, $by) {
            if ($post->status_id !== $to->id) {
                $this->applyStatus($post, $to, $from, null, true, $by->id);
            }

            foreach (array_values(array_unique($orderedIds)) as $index => $id) {
                Post::query()->where('board_id', $board->id)->where('status_id', $to->id)->whereKey($id)->update(['roadmap_order' => $index]);
            }
        });

        if ($from !== $to->id) {
            event(new PostStatusChanged($post->fresh(), $from, $to->id));
        }

        return $this->roadmap($board);
    }

    // ─── Internals ───────────────────────────────────────────────────

    /** Write the status column + history row (caller owns the transaction). */
    public function applyStatus(Post $post, Status $to, ?int $fromId, ?string $note, bool $isPublic, ?int $userId): void
    {
        $post->status_id = $to->id;
        $post->last_activity_at = now();
        $post->save();

        StatusChange::create([
            'post_id' => $post->id,
            'from_status_id' => $fromId,
            'to_status_id' => $to->id,
            'changed_by' => $userId,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            'is_public' => $isPublic,
            'created_at' => now(),
        ]);
    }

    private function listQuery(): Builder
    {
        return Post::query()
            ->with(['board:id,slug,name', 'status', 'tags', 'visitor'])
            ->withCount(['comments as pending_comments_count' => fn ($q) => $q->where('moderation_state', 'pending')]);
    }

    private function resolveStatus(Board $board, ?int $statusId): Status
    {
        $status = $statusId
            ? Status::query()->where('board_id', $board->id)->find($statusId)
            : Status::query()->where('board_id', $board->id)->where('is_default', true)->first();

        if (! $status) {
            throw new RoadmapException('The status does not belong to this board.', 'invalid_status');
        }

        return $status;
    }

    /** @param  int[]  $ids @return int[] */
    private function boardTagIds(int $boardId, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return Tag::query()->where('board_id', $boardId)->whereIn('id', array_unique($ids))->pluck('id')->all();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
