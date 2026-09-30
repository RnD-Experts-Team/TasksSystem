<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Status;
use App\Models\Roadmap\Tag;
use App\Support\Roadmap\RoadmapNotFound;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Public (read only) access to boards. Archived boards do not exist for visitors. */
class BoardService
{
    /**
     * @throws RoadmapNotFound
     */
    public function findBySlug(string $slug): Board
    {
        $board = Board::query()->where('slug', $slug)->where('is_archived', false)->first();

        if (! $board) {
            throw new RoadmapNotFound('Board not found');
        }

        return $board;
    }

    /**
     * Non-archived boards with the number of publicly visible posts (`posts_count`).
     *
     * @return Collection<int,Board>
     */
    public function summaries(): Collection
    {
        return Board::query()
            ->where('is_archived', false)
            ->withCount(['posts' => fn ($q) => $q->where('moderation_state', 'approved')->whereNull('merged_into_post_id')])
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /**
     * Statuses + tags of a board with counts of publicly visible posts.
     *
     * @return array{statuses: Collection<int,Status>, tags: Collection<int,Tag>, status_counts: array<int,int>, tag_counts: array<int,int>, posts_count: int}
     */
    public function detail(Board $board): array
    {
        $statuses = Status::query()->where('board_id', $board->id)->orderBy('sort_order')->orderBy('id')->get();
        $tags = Tag::query()->where('board_id', $board->id)->orderBy('sort_order')->orderBy('id')->get();

        $statusCounts = Post::query()->publiclyVisible()->where('board_id', $board->id)
            ->select('status_id', DB::raw('count(*) as c'))->groupBy('status_id')
            ->pluck('c', 'status_id')->map(fn ($c) => (int) $c)->all();

        $tagCounts = DB::table('roadmap_post_tag')
            ->join('roadmap_posts', 'roadmap_posts.id', '=', 'roadmap_post_tag.post_id')
            ->where('roadmap_posts.board_id', $board->id)
            ->where('roadmap_posts.moderation_state', 'approved')
            ->whereNull('roadmap_posts.merged_into_post_id')
            ->select('roadmap_post_tag.tag_id', DB::raw('count(*) as c'))->groupBy('roadmap_post_tag.tag_id')
            ->pluck('c', 'tag_id')->map(fn ($c) => (int) $c)->all();

        return [
            'statuses' => $statuses,
            'tags' => $tags,
            'status_counts' => $statusCounts,
            'tag_counts' => $tagCounts,
            'posts_count' => array_sum($statusCounts),
        ];
    }
}
