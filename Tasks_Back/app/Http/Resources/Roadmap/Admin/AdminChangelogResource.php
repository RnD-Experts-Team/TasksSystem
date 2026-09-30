<?php

namespace App\Http\Resources\Roadmap\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** AdminChangelogEntry (+ detail fields when the relation `posts` is loaded). @mixin \App\Models\Roadmap\ChangelogEntry */
class AdminChangelogResource extends JsonResource
{
    public function __construct($resource, private bool $detail = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'board' => $this->board ? ['id' => $this->board->id, 'slug' => $this->board->slug, 'name' => $this->board->name] : null,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'label' => $this->label,
            'status' => $this->status,
            'published_at' => AdminSupport::iso($this->published_at),
            'is_scheduled' => $this->status === 'published' && $this->published_at !== null && $this->published_at->isFuture(),
            'linked_posts_count' => (int) ($this->linked_posts_count ?? ($this->relationLoaded('posts') ? $this->posts->count() : 0)),
            'created_at' => AdminSupport::iso($this->created_at),
            'updated_at' => AdminSupport::iso($this->updated_at),
        ];

        if ($this->detail) {
            $data += [
                'body_md' => $this->body_md,
                'body_html' => $this->body_html,
                'linked_posts' => $this->relationLoaded('posts')
                    ? $this->posts->map(fn ($p) => [
                        'id' => $p->id,
                        'number' => (int) $p->number,
                        'board_slug' => (string) $p->board?->slug,
                        'title' => $p->title,
                    ])->values()->all()
                    : [],
            ];
        }

        return $data;
    }
}
