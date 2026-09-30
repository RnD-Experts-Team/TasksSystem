<?php

namespace App\Http\Requests\Roadmap\Admin;

class RoadmapMoveRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'post_id' => 'required|integer',
            'to_status_id' => 'required|integer',
            'ordered_ids' => 'present|array|max:500',
            'ordered_ids.*' => 'integer',
        ];
    }
}
