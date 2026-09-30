<?php

namespace App\Http\Resources\Roadmap\Public;

use App\Models\Roadmap\Status;

/** StatusRef {slug,name,color,kind} */
class PublicStatusRefResource extends PublicResource
{
    public function toArray($request): array
    {
        /** @var Status $s */
        $s = $this->resource;

        return [
            'slug' => $s->slug,
            'name' => $s->name,
            'color' => $s->color,
            'kind' => $s->kind,
        ];
    }
}
