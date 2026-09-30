<?php

namespace App\Http\Resources\Roadmap\Admin;

use App\Models\Roadmap\Status;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Status */
class AdminStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
            'kind' => $this->kind,
            'sort_order' => (int) $this->sort_order,
            'is_roadmap_column' => (bool) $this->is_roadmap_column,
            'is_default' => (bool) $this->is_default,
            'locks_voting' => (bool) $this->locks_voting,
            'posts_count' => (int) ($this->posts_count ?? 0),
        ];
    }
}
