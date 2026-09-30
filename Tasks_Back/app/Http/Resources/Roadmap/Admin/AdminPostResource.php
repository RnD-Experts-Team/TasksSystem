<?php

namespace App\Http\Resources\Roadmap\Admin;

use App\Support\Roadmap\TextSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** AdminPostListItem. @mixin \App\Models\Roadmap\Post */
class AdminPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board' => [
                'id' => $this->board_id,
                'slug' => $this->board?->slug,
                'name' => $this->board?->name,
            ],
            'number' => (int) $this->number,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => TextSanitizer::excerpt($this->body, 200),
            'author_name' => $this->author_name,
            'visitor' => AdminSupport::visitorRef($this->visitor),
            'created_by_admin' => $this->created_by_user_id !== null,
            'moderation_state' => $this->moderation_state,
            'status' => AdminSupport::status($this->status),
            'tags' => $this->tags->map(fn ($t) => AdminSupport::tag($t))->values()->all(),
            'votes_count' => (int) $this->votes_count,
            'comments_count' => (int) $this->comments_count,
            'pending_comments_count' => (int) ($this->pending_comments_count ?? 0),
            'is_pinned' => (bool) $this->is_pinned,
            'flags' => AdminSupport::flags($this->flags),
            'merged_into_post_id' => $this->merged_into_post_id,
            'created_at' => AdminSupport::iso($this->created_at),
            'published_at' => AdminSupport::iso($this->published_at),
            'last_activity_at' => AdminSupport::iso($this->last_activity_at),
        ];
    }
}
