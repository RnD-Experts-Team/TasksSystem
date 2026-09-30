<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\Post;
use App\Models\Roadmap\Status;
use Illuminate\Support\Collection;

/** RoadmapData {columns: [{status, total, posts[]}], generated_at} */
class PublicRoadmapResource extends PublicResource
{
    /** @param  array<int,array{status: Status, total: int, posts: Collection<int,Post>}>  $columns */
    public function __construct(protected array $columns, protected string $boardSlug, protected string $teamName)
    {
        parent::__construct(null);
    }

    public function toArray($request): array
    {
        return [
            'columns' => array_map(fn (array $c) => [
                'status' => (new PublicStatusRefResource($c['status']))->resolve(),
                'total' => (int) $c['total'],
                'posts' => $c['posts']->map(
                    fn (Post $p) => (new PublicPostListResource($p, $this->boardSlug, $this->teamName))->resolve()
                )->values()->all(),
            ], $this->columns),
            'generated_at' => now()->toIso8601ZuluString(),
        ];
    }
}
