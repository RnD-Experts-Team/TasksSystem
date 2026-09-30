<?php

namespace App\Http\Resources\Roadmap\Admin;

use App\Models\Roadmap\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tag */
class AdminTagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
            'sort_order' => (int) $this->sort_order,
            'posts_count' => (int) ($this->posts_count ?? 0),
        ];
    }
}
