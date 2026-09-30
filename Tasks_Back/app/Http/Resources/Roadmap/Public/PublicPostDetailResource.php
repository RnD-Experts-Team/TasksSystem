<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\StatusChange;
use Illuminate\Support\Collection;

/**
 * PublicPostDetail. Expects: status, tags, board (slug,name), mergedInto.board, and the public
 * status history (with fromStatus/toStatus) + live changelog entries passed in explicitly.
 */
class PublicPostDetailResource extends PublicResource
{
    /**
     * @param  Collection<int,StatusChange>  $history
     * @param  Collection<int,ChangelogEntry>  $changelog
     */
    public function __construct(
        Post $post,
        protected string $teamName,
        protected Collection $history,
        protected Collection $changelog,
        protected bool $ownerPending = false,
        protected ?string $viewerState = null,
    ) {
        parent::__construct($post);
    }

    public function toArray($request): array
    {
        /** @var Post $p */
        $p = $this->resource;
        $boardSlug = $p->board->slug;

        $base = (new PublicPostListResource($p, $boardSlug, $this->teamName))->resolve();

        $response = null;
        if ($p->response_html !== null && $p->response_html !== '') {
            $response = [
                'body_html' => $p->response_html,
                'responded_at' => $this->iso($p->responded_at ?? $p->updated_at),
                'by' => $this->teamName,
            ];
        }

        $mergedInto = null;
        if ($p->merged_into_post_id !== null && $p->mergedInto && $p->mergedInto->moderation_state === 'approved' && $p->mergedInto->board) {
            $mergedInto = [
                'board_slug' => $p->mergedInto->board->slug,
                'number' => (int) $p->mergedInto->number,
                'slug' => $p->mergedInto->slug,
            ];
        }

        return array_merge($base, [
            'body' => (string) $p->body,
            'response' => $response,
            'status_history' => $this->history->map(fn (StatusChange $c) => [
                'from' => $c->fromStatus ? [
                    'slug' => $c->fromStatus->slug,
                    'name' => $c->fromStatus->name,
                    'color' => $c->fromStatus->color,
                ] : null,
                'to' => [
                    'slug' => $c->toStatus->slug,
                    'name' => $c->toStatus->name,
                    'color' => $c->toStatus->color,
                ],
                'note' => $c->is_public ? $c->note : null,
                'at' => $this->iso($c->created_at),
            ])->values()->all(),
            'merged_into' => $mergedInto,
            'related_changelog' => $this->changelog->map(fn (ChangelogEntry $e) => [
                'slug' => $e->slug,
                'title' => $e->title,
                'label' => $e->label,
                'published_at' => $this->iso($e->published_at),
            ])->values()->all(),
            'board' => ['slug' => $boardSlug, 'name' => $p->board->name],
            'canonical_slug' => $p->slug,
            'viewer' => [
                'is_owner_pending' => $this->ownerPending,
                'moderation_state' => $this->viewerState,
            ],
        ]);
    }
}
