<?php

namespace App\Services\Roadmap;

use App\Events\Roadmap\ChangelogPublished;
use App\Exceptions\RoadmapException;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\SlugRedirect;
use App\Models\Roadmap\Status;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin side of the changelog. Scheduling needs no scheduler: a published entry with a
 * future published_at simply stays hidden from the public scope until its date.
 */
class ChangelogService
{
    public function __construct(
        private MarkdownRenderer $markdown,
        private AdminPostService $posts,
    ) {}

    /** @param array<string,mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if (! empty($filters['board_id'])) {
            $query->where('board_id', (int) $filters['board_id']);
        }

        return $query
            ->orderByRaw('CASE WHEN published_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(max(1, min(50, (int) ($filters['per_page'] ?? 20))));
    }

    public function find(int $id): ?ChangelogEntry
    {
        return $this->baseQuery()->with(['posts.board:id,slug'])->find($id);
    }

    public function create(array $data, User $by): ChangelogEntry
    {
        $entry = ChangelogEntry::create([
            'board_id' => $data['board_id'] ?? null,
            'title' => trim($data['title']),
            'slug' => $this->uniqueSlug($data['slug'] ?? null, $data['title']),
            'summary' => $this->nullable($data['summary'] ?? null),
            'label' => $data['label'],
            'body_md' => $data['body_md'],
            'body_html' => $this->markdown->render($data['body_md']),
            'status' => 'draft',
            'published_at' => $this->date($data['published_at'] ?? null),
            'created_by' => $by->id,
            'updated_by' => $by->id,
        ]);

        return $this->find($entry->id);
    }

    public function update(ChangelogEntry $entry, array $data, User $by): ChangelogEntry
    {
        DB::transaction(function () use ($entry, $data, $by) {
            if (array_key_exists('slug', $data) && $data['slug'] && $data['slug'] !== $entry->slug) {
                $old = $entry->slug;
                $entry->slug = $this->uniqueSlug($data['slug'], $entry->title, $entry->id);
                if ($entry->slug !== $old) {
                    SlugRedirect::updateOrCreate(
                        ['kind' => 'changelog', 'old_key' => $old],
                        ['target_id' => $entry->id, 'created_at' => now()]
                    );
                    SlugRedirect::query()->where('kind', 'changelog')->where('old_key', $entry->slug)->delete();
                }
            }

            foreach (['title', 'label', 'board_id'] as $key) {
                if (array_key_exists($key, $data)) {
                    $entry->{$key} = $key === 'title' ? trim($data[$key]) : $data[$key];
                }
            }
            if (array_key_exists('summary', $data)) {
                $entry->summary = $this->nullable($data['summary']);
            }
            if (array_key_exists('body_md', $data)) {
                $entry->body_md = $data['body_md'];
                $entry->body_html = $this->markdown->render($data['body_md']);
            }
            if (array_key_exists('published_at', $data)) {
                $entry->published_at = $this->date($data['published_at']);
            }
            $entry->updated_by = $by->id;
            $entry->save();
        });

        return $this->find($entry->id);
    }

    public function delete(ChangelogEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            SlugRedirect::query()->where('kind', 'changelog')->where('target_id', $entry->id)->delete();
            $entry->delete(); // changelog_post cascades
        });
    }

    /**
     * Publish now, or at a given date (future date = scheduled). Without an explicit date a
     * still-future date stored on the draft is kept; otherwise it goes live immediately.
     */
    public function publish(ChangelogEntry $entry, ?string $publishedAt, User $by): ChangelogEntry
    {
        $date = $this->date($publishedAt);
        if (! $date && $entry->published_at && $entry->published_at->isFuture()) {
            $date = $entry->published_at;
        }

        $entry->forceFill([
            'status' => 'published',
            'published_at' => $date ?? now(),
            'updated_by' => $by->id,
        ])->save();

        if (! $entry->published_at->isFuture()) {
            event(new ChangelogPublished($entry));
        }

        return $this->find($entry->id);
    }

    public function unpublish(ChangelogEntry $entry, User $by): ChangelogEntry
    {
        $entry->forceFill(['status' => 'draft', 'updated_by' => $by->id])->save();

        return $this->find($entry->id);
    }

    /**
     * Link shipped requests to the entry; optionally move them to a status (only posts whose
     * board owns that status are moved).
     *
     * @param  int[]  $postIds
     */
    public function syncPosts(ChangelogEntry $entry, array $postIds, ?int $markStatusId, User $by): ChangelogEntry
    {
        $postIds = array_values(array_unique($postIds));

        DB::transaction(function () use ($entry, $postIds, $markStatusId, $by) {
            $valid = Post::query()->whereIn('id', $postIds)->get();
            $entry->posts()->sync($valid->pluck('id')->all());

            if ($markStatusId) {
                $status = Status::query()->find($markStatusId);
                if (! $status) {
                    throw new RoadmapException('The status does not exist.', 'invalid_status');
                }
                foreach ($valid as $post) {
                    if ($post->board_id === $status->board_id && $post->status_id !== $status->id) {
                        $this->posts->changeStatus($post, $status->id, 'Shipped: '.$entry->title, true, $by);
                    }
                }
            }
        });

        return $this->find($entry->id);
    }

    // ─── Internals ───────────────────────────────────────────────────

    private function baseQuery(): Builder
    {
        return ChangelogEntry::query()->with('board:id,slug,name')->withCount('posts as linked_posts_count');
    }

    private function uniqueSlug(?string $requested, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($requested ?: $title);
        $base = substr($base !== '' ? $base : 'update', 0, 90);

        $slug = $base;
        $n = 2;
        while (ChangelogEntry::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function date(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    private function nullable(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
