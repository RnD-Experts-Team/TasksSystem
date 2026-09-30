<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Status;
use Illuminate\Support\Collection;

/** Public roadmap columns: statuses flagged `is_roadmap_column`, in sort order. */
class PublicRoadmapService
{
    /**
     * @return array<int,array{status: Status, total: int, posts: Collection<int,Post>}>
     */
    public function columns(Board $board, int $perColumn = 10): array
    {
        $perColumn = max(1, min(20, $perColumn));

        $statuses = Status::query()
            ->where('board_id', $board->id)->where('is_roadmap_column', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        return $statuses->map(function (Status $status) use ($board, $perColumn) {
            $base = Post::query()->publiclyVisible()->where('board_id', $board->id)->where('status_id', $status->id);

            return [
                'status' => $status,
                'total' => (clone $base)->count(),
                'posts' => (clone $base)
                    ->with(['status', 'tags'])
                    ->orderByDesc('is_pinned')->orderBy('roadmap_order')->orderByDesc('votes_count')->orderByDesc('id')
                    ->limit($perColumn)->get(),
            ];
        })->all();
    }
}
