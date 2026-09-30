<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\Board;

/** BoardSummary. Expects a `posts_count` withCount/attribute. */
class PublicBoardSummaryResource extends PublicResource
{
    public function toArray($request): array
    {
        /** @var Board $b */
        $b = $this->resource;

        return [
            'slug' => $b->slug,
            'name' => $b->name,
            'description' => $b->description,
            'icon' => $b->icon,
            'posts_count' => (int) $b->getAttribute('posts_count'),
        ];
    }
}
