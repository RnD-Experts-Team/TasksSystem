<?php

namespace App\Http\Resources\Roadmap\Admin;

use App\Models\Roadmap\Board;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Board */
class AdminBoardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'sort_order' => (int) $this->sort_order,
            'is_archived' => (bool) $this->is_archived,
            'voting_mode' => $this->voting_mode,
            'allow_submissions' => (bool) $this->allow_submissions,
            'allow_comments' => (bool) $this->allow_comments,
            'allow_votes' => (bool) $this->allow_votes,
            'require_post_approval' => (bool) $this->require_post_approval,
            'require_comment_approval' => (bool) $this->require_comment_approval,
            'trust_after_approved' => $this->trust_after_approved !== null ? (int) $this->trust_after_approved : null,
            'posts_count' => (int) ($this->posts_count ?? 0),
            'pending_count' => (int) ($this->pending_count ?? 0),
            'created_at' => AdminSupport::iso($this->created_at),
            'updated_at' => AdminSupport::iso($this->updated_at),
        ];

        if ($this->relationLoaded('statuses')) {
            $data['statuses'] = AdminStatusResource::collection($this->statuses)->resolve($request);
        }
        if ($this->relationLoaded('tags')) {
            $data['tags'] = AdminTagResource::collection($this->tags)->resolve($request);
        }

        return $data;
    }
}
