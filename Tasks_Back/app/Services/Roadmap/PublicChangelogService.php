<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\SlugRedirect;
use App\Support\Roadmap\RoadmapNotFound;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Public reads of the changelog: only published entries whose date is due (Live scope). */
class PublicChangelogService
{
    public function list(?string $boardSlug, ?string $label, int $perPage, int $page): LengthAwarePaginator
    {
        $query = ChangelogEntry::query()->live()->with('board:id,slug,name');

        if ($boardSlug !== null && $boardSlug !== '') {
            $boardId = Board::query()->where('slug', $boardSlug)->where('is_archived', false)->value('id');
            if ($boardId === null) {
                throw new RoadmapNotFound('Board not found');
            }
            // a board's changelog also shows the "all products" entries
            $query->where(fn ($q) => $q->where('board_id', $boardId)->orWhereNull('board_id'));
        }

        if ($label !== null && $label !== '') {
            $query->where('label', $label);
        }

        return $query->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(max(1, min(30, $perPage)), ['*'], 'page', max(1, $page));
    }

    /** Falls back to `slug_redirects` (kind changelog) for renamed entries. */
    public function find(string $slug): ChangelogEntry
    {
        $entry = ChangelogEntry::query()->live()->with('board:id,slug,name')->where('slug', $slug)->first();

        if (! $entry) {
            $targetId = SlugRedirect::query()->where('kind', 'changelog')->where('old_key', $slug)->value('target_id');
            if ($targetId !== null) {
                $entry = ChangelogEntry::query()->live()->with('board:id,slug,name')->whereKey($targetId)->first();
            }
        }

        if (! $entry) {
            throw new RoadmapNotFound('Entry not found');
        }

        return $entry;
    }

    /** Linked posts that are still publicly visible. @return Collection<int,Post> */
    public function relatedPosts(ChangelogEntry $entry): Collection
    {
        return $entry->posts()->publiclyVisible()->with('board:id,slug,name')->orderBy('roadmap_posts.id')->get();
    }
}
