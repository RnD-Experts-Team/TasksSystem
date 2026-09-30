<?php

namespace App\Http\Requests\Roadmap\Admin;

class CommentIndexRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'moderation_state' => 'nullable|in:all,pending,approved,rejected,spam',
            'post_id' => 'nullable|integer',
            'visitor_id' => 'nullable|string|size:26',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
