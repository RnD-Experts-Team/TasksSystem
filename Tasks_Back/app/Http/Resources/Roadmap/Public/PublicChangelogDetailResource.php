<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use Illuminate\Support\Collection;

/** ChangelogDetail = list item + body_html + related_posts (publicly visible posts only). */
class PublicChangelogDetailResource extends PublicResource
{
    /** @param  Collection<int,Post>  $relatedPosts  with `board` loaded */
    public function __construct(ChangelogEntry $entry, protected Collection $relatedPosts)
    {
        parent::__construct($entry);
    }

    public function toArray($request): array
    {
        /** @var ChangelogEntry $e */
        $e = $this->resource;

        return array_merge((new PublicChangelogListResource($e))->resolve(), [
            'body_html' => (string) $e->body_html,
            'related_posts' => $this->relatedPosts->map(fn (Post $p) => [
                'board_slug' => $p->board->slug,
                'number' => (int) $p->number,
                'slug' => $p->slug,
                'title' => $p->title,
                'url_path' => static::postPath($p->board->slug, (int) $p->number, $p->slug),
            ])->values()->all(),
        ]);
    }
}
