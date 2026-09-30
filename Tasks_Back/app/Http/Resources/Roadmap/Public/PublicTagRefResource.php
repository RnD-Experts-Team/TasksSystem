<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\Tag;

/** TagRef {slug,name,color} */
class PublicTagRefResource extends PublicResource
{
    public function toArray($request): array
    {
        /** @var Tag $t */
        $t = $this->resource;

        return [
            'slug' => $t->slug,
            'name' => $t->name,
            'color' => $t->color,
        ];
    }
}
