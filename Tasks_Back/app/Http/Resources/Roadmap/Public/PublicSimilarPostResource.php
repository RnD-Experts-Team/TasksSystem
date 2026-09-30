<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\Post;

/** SimilarPost */
class PublicSimilarPostResource extends PublicResource
{
    public function __construct(Post $post, protected string $boardSlug)
    {
        parent::__construct($post);
    }

    public function toArray($request): array
    {
        /** @var Post $p */
        $p = $this->resource;

        return [
            'number' => (int) $p->number,
            'slug' => $p->slug,
            'title' => $p->title,
            'votes_count' => (int) $p->votes_count,
            'status' => [
                'slug' => $p->status->slug,
                'name' => $p->status->name,
                'color' => $p->status->color,
            ],
            'url_path' => static::postPath($this->boardSlug, (int) $p->number, $p->slug),
        ];
    }
}
