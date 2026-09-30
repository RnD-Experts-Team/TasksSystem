<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\Post;
use App\Support\Roadmap\TextSanitizer;

/**
 * PublicPostListItem. Expects `status` and `tags` to be eager loaded.
 * Deliberately excludes every internal column (ids, visitor, ip/content hash, flags, moderation).
 */
class PublicPostListResource extends PublicResource
{
    public function __construct(Post $post, protected string $boardSlug, protected string $teamName)
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
            'excerpt' => TextSanitizer::excerpt($p->body, 200),
            'author' => static::author($p->created_by_user_id !== null, $p->author_name, $this->teamName),
            'status' => (new PublicStatusRefResource($p->status))->resolve(),
            'tags' => $p->tags->map(fn ($t) => (new PublicTagRefResource($t))->resolve())->values()->all(),
            'votes_count' => (int) $p->votes_count,
            'comments_count' => (int) $p->comments_count,
            'is_pinned' => (bool) $p->is_pinned,
            'has_response' => $p->response_html !== null && $p->response_html !== '',
            'published_at' => $this->iso($p->published_at),
            'url_path' => static::postPath($this->boardSlug, (int) $p->number, $p->slug),
        ];
    }

    /** PublicAuthor: staff content shows the configured team name only (never a user name/id). */
    public static function author(bool $isTeam, ?string $name, string $teamName): array
    {
        if ($isTeam) {
            return ['name' => $teamName, 'is_team' => true];
        }

        return ['name' => ($name !== null && $name !== '') ? $name : 'Anonymous', 'is_team' => false];
    }
}
