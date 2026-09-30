<?php

namespace App\Services\Roadmap;

use App\Exceptions\RoadmapException;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Setting;
use App\Models\Roadmap\SlugRedirect;
use App\Models\Roadmap\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Admin CRUD for boards: default status seeding, slug redirects, ordering. */
class BoardAdminService
{
    public const RESERVED_SLUGS = ['p', 'roadmap', 'changelog', 'feed', 'new', 'me', 'api', 'admin'];

    /** name, slug, colour, kind, roadmap column, default, locks voting */
    private const DEFAULT_STATUSES = [
        ['Under review', 'under-review', '#64748b', 'open', false, true, false],
        ['Planned', 'planned', '#6366f1', 'planned', true, false, false],
        ['In progress', 'in-progress', '#f59e0b', 'in_progress', true, false, false],
        ['Live', 'live', '#10b981', 'done', true, false, false],
        ['Not planned', 'not-planned', '#ef4444', 'closed', false, false, true],
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return Collection<int, Board> */
    public function list(bool $withArchived = true): Collection
    {
        return $this->withCounts(Board::query())
            ->when(! $withArchived, fn (Builder $q) => $q->where('is_archived', false))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function find(int $id): ?Board
    {
        return $this->withCounts(Board::query())->with(['statuses' => fn ($q) => $q->withCount('posts'), 'tags' => fn ($q) => $q->withCount('posts')])->find($id);
    }

    public function create(array $data): Board
    {
        return DB::transaction(function () use ($data) {
            $slug = $this->uniqueSlug($data['slug'] ?? null, $data['name']);
            $order = ((int) Board::query()->max('sort_order')) + 1;

            $board = Board::create([
                'slug' => $slug,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? null,
                'sort_order' => $order,
                'is_archived' => (bool) ($data['is_archived'] ?? false),
                'voting_mode' => $data['voting_mode'] ?? 'anonymous',
                'allow_submissions' => (bool) ($data['allow_submissions'] ?? true),
                'allow_comments' => (bool) ($data['allow_comments'] ?? true),
                'allow_votes' => (bool) ($data['allow_votes'] ?? true),
                'require_post_approval' => (bool) ($data['require_post_approval'] ?? true),
                'require_comment_approval' => (bool) ($data['require_comment_approval'] ?? true),
                'trust_after_approved' => array_key_exists('trust_after_approved', $data) ? $data['trust_after_approved'] : 3,
                'next_post_number' => 1,
            ]);

            $this->seedStatuses($board);
            $this->dropRedirect('board', $slug);
            $this->settings->forgetAll();

            return $this->find($board->id);
        });
    }

    public function update(Board $board, array $data): Board
    {
        return DB::transaction(function () use ($board, $data) {
            $old = $board->slug;

            if (array_key_exists('slug', $data) && $data['slug'] !== null && $data['slug'] !== '' && $data['slug'] !== $old) {
                $new = $this->uniqueSlug($data['slug'], $board->name, $board->id);
                if ($new !== $old) {
                    $board->slug = $new;
                    // Old links keep working through the SEO shell.
                    SlugRedirect::updateOrCreate(
                        ['kind' => 'board', 'old_key' => $old],
                        ['target_id' => $board->id, 'created_at' => now()]
                    );
                    $this->dropRedirect('board', $new);
                }
            }

            $fillable = ['name', 'description', 'icon', 'is_archived', 'voting_mode', 'allow_submissions', 'allow_comments', 'allow_votes', 'require_post_approval', 'require_comment_approval', 'trust_after_approved'];
            foreach ($fillable as $key) {
                if (array_key_exists($key, $data)) {
                    $board->{$key} = $data[$key];
                }
            }

            $board->save();
            $this->settings->forgetAll();

            return $this->find($board->id);
        });
    }

    public function delete(Board $board): void
    {
        if ($board->posts()->exists()) {
            throw new RoadmapException('This board has posts. Archive it instead of deleting it.', 'board_not_empty');
        }

        DB::transaction(function () use ($board) {
            SlugRedirect::query()->where('kind', 'board')->where('target_id', $board->id)->delete();
            Setting::query()->where('scope', 'board:'.$board->id)->delete();
            $board->delete(); // statuses/tags cascade
            $this->settings->forgetAll();
        });
    }

    /** @param  int[]  $ids */
    public function reorder(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $index => $id) {
                Board::query()->whereKey($id)->update(['sort_order' => $index]);
            }
        });
        $this->settings->forgetAll();
    }

    // ─── Internals ───────────────────────────────────────────────────

    private function seedStatuses(Board $board): void
    {
        foreach (self::DEFAULT_STATUSES as $i => [$name, $slug, $color, $kind, $column, $default, $locks]) {
            Status::create([
                'board_id' => $board->id,
                'name' => $name,
                'slug' => $slug,
                'color' => $color,
                'kind' => $kind,
                'sort_order' => $i,
                'is_roadmap_column' => $column,
                'is_default' => $default,
                'locks_voting' => $locks,
            ]);
        }
    }

    /** Slug from the requested value or the name; reserved words and taken slugs get a numeric suffix. */
    private function uniqueSlug(?string $requested, string $name, ?int $ignoreId = null): string
    {
        $base = $requested ? Str::slug($requested) : Str::slug($name);
        $base = substr($base !== '' ? $base : 'board', 0, 58);

        if (in_array($base, self::RESERVED_SLUGS, true)) {
            $base .= '-board';
        }

        $slug = $base;
        $n = 2;
        while (Board::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function dropRedirect(string $kind, string $key): void
    {
        SlugRedirect::query()->where('kind', $kind)->where('old_key', $key)->delete();
    }

    private function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'posts as posts_count' => fn ($q) => $q->where('moderation_state', 'approved')->whereNull('merged_into_post_id'),
            'posts as pending_count' => fn ($q) => $q->where('moderation_state', 'pending'),
        ]);
    }
}
