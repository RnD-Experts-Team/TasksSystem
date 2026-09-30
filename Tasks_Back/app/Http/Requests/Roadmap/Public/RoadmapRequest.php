<?php

namespace App\Http\Requests\Roadmap\Public;

/** GET /boards/{board}/roadmap */
class RoadmapRequest extends PublicRequest
{
    public function rules(): array
    {
        return [
            'per_column' => 'nullable|integer|min:1|max:20',
        ];
    }
}
