<?php

namespace App\Http\Resources\Roadmap\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AdminRoadmapData. The resource is the array returned by AdminPostService::roadmap():
 * ['board' => Board, 'columns' => [['status' => Status, 'posts' => Collection<Post>], ...]].
 */
class AdminRoadmapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'board' => [
                'id' => $this->resource['board']->id,
                'slug' => $this->resource['board']->slug,
                'name' => $this->resource['board']->name,
            ],
            'columns' => array_map(fn (array $column) => [
                'status' => AdminSupport::status($column['status']),
                'posts' => $column['posts']->map(fn ($p) => [
                    'id' => $p->id,
                    'number' => (int) $p->number,
                    'title' => $p->title,
                    'votes_count' => (int) $p->votes_count,
                    'comments_count' => (int) $p->comments_count,
                    'is_pinned' => (bool) $p->is_pinned,
                    'tags' => $p->tags->map(fn ($t) => AdminSupport::tag($t))->values()->all(),
                    'roadmap_order' => (int) $p->roadmap_order,
                ])->values()->all(),
            ], $this->resource['columns']),
        ];
    }
}
