<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\Status;
use App\Models\Roadmap\Tag;
use Illuminate\Support\Collection;

/** BoardDetail {board, statuses, tags} with per-status / per-tag counts of publicly visible posts. */
class PublicBoardDetailResource extends PublicResource
{
    /**
     * @param  Collection<int,Status>  $statuses
     * @param  Collection<int,Tag>  $tags
     * @param  array<int,int>  $statusCounts  status id => count
     * @param  array<int,int>  $tagCounts  tag id => count
     */
    public function __construct(
        Board $board,
        protected Collection $statuses,
        protected Collection $tags,
        protected array $statusCounts,
        protected array $tagCounts,
        protected int $postsCount,
    ) {
        parent::__construct($board);
    }

    public function toArray($request): array
    {
        /** @var Board $b */
        $b = $this->resource;

        return [
            'board' => [
                'slug' => $b->slug,
                'name' => $b->name,
                'description' => $b->description,
                'icon' => $b->icon,
                'allow_submissions' => (bool) $b->allow_submissions,
                'allow_comments' => (bool) $b->allow_comments,
                'allow_votes' => (bool) $b->allow_votes,
                'voting_mode' => $b->voting_mode,
                'requires_review' => (bool) $b->require_post_approval,
                'posts_count' => $this->postsCount,
            ],
            'statuses' => $this->statuses->map(fn (Status $s) => array_merge(
                (new PublicStatusRefResource($s))->resolve(),
                [
                    'is_roadmap_column' => (bool) $s->is_roadmap_column,
                    'is_default' => (bool) $s->is_default,
                    'locks_voting' => (bool) $s->locks_voting,
                    'posts_count' => (int) ($this->statusCounts[$s->id] ?? 0),
                ]
            ))->values()->all(),
            'tags' => $this->tags->map(fn (Tag $t) => array_merge(
                (new PublicTagRefResource($t))->resolve(),
                ['posts_count' => (int) ($this->tagCounts[$t->id] ?? 0)]
            ))->values()->all(),
        ];
    }
}
