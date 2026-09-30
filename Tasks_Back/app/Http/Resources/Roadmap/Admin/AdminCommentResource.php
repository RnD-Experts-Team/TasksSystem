<?php

namespace App\Http\Resources\Roadmap\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** AdminComment. Eager load `post.board` and `visitor`. @mixin \App\Models\Roadmap\Comment */
class AdminCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post' => [
                'id' => $this->post_id,
                'number' => (int) $this->post?->number,
                'title' => (string) $this->post?->title,
                'board_slug' => (string) $this->post?->board?->slug,
            ],
            'parent_id' => $this->parent_id,
            'author_name' => $this->author_name,
            'is_admin' => (bool) $this->is_admin,
            'body' => $this->body,
            'moderation_state' => $this->moderation_state,
            'visitor' => AdminSupport::visitorRef($this->visitor),
            'flags' => AdminSupport::flags($this->flags),
            'created_at' => AdminSupport::iso($this->created_at),
        ];
    }
}
