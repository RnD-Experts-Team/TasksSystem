<?php

namespace App\Http\Requests\Roadmap\Admin;

class PostIndexRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'board_id' => 'nullable|integer|exists:roadmap_boards,id',
            'moderation_state' => 'nullable|in:all,pending,approved,rejected,spam',
            'status_id' => 'nullable|integer',
            'tag_id' => 'nullable|integer',
            'q' => 'nullable|string|max:140',
            'sort' => 'nullable|in:votes,new,activity',
            'visitor_id' => 'nullable|string|size:26',
            'ip_hash' => 'nullable|string|max:32',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ];
    }
}
