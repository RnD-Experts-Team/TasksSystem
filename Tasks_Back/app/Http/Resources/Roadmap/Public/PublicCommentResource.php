<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\Comment;
use Illuminate\Support\Collection;

/** PublicComment (one nesting level). Staff replies show the team name only. */
class PublicCommentResource extends PublicResource
{
    /** @param  Collection<int,Comment>  $replies */
    public function __construct(Comment $comment, protected string $teamName, protected Collection $replies)
    {
        parent::__construct($comment);
    }

    public function toArray($request): array
    {
        /** @var Comment $c */
        $c = $this->resource;

        return [
            'id' => (int) $c->id,
            'parent_id' => $c->parent_id !== null ? (int) $c->parent_id : null,
            'author' => PublicPostListResource::author((bool) $c->is_admin, $c->author_name, $this->teamName),
            'body' => $c->body,
            'created_at' => $this->iso($c->created_at),
            'replies' => $this->replies->map(fn (Comment $r) => (new self($r, $this->teamName, collect()))->resolve())->values()->all(),
        ];
    }
}
