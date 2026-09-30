<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\ChangelogEntry;

/** ChangelogListItem. Expects `board` loaded (nullable). */
class PublicChangelogListResource extends PublicResource
{
    public function toArray($request): array
    {
        /** @var ChangelogEntry $e */
        $e = $this->resource;

        return [
            'slug' => $e->slug,
            'title' => $e->title,
            'summary' => $e->summary,
            'label' => $e->label,
            'published_at' => $this->iso($e->published_at),
            'board' => $e->board ? ['slug' => $e->board->slug, 'name' => $e->board->name] : null,
            'url_path' => '/changelog/'.$e->slug,
        ];
    }
}
