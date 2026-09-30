<?php

namespace App\Http\Resources\Roadmap\Admin;

use App\Models\Roadmap\Visitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AdminVisitor. token_hash is never exposed; only the ip hash (needed for bulk removal) is.
 * Set the `suspicious` attribute on the model before resolving.
 *
 * @mixin Visitor
 */
class AdminVisitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return AdminSupport::visitorRef($this->resource) + [
            'ip_hash' => AdminSupport::visitorIp($this->resource),
            'banned_reason' => $this->banned_reason,
            'banned_at' => AdminSupport::iso($this->banned_at),
            'first_seen_at' => AdminSupport::iso($this->first_seen_at),
            'last_seen_at' => AdminSupport::iso($this->last_seen_at),
            'posts_count' => (int) $this->posts_count,
            'comments_count' => (int) $this->comments_count,
            'approved_comments_count' => (int) $this->approved_comments_count,
            'votes_count' => (int) $this->votes_count,
            'suspicious' => (bool) ($this->resource->getAttribute('suspicious') ?? false),
        ];
    }
}
