<?php

namespace App\Http\Requests\Roadmap\Admin;

class VisitorIndexRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'q' => 'nullable|string|max:64',
            'banned' => 'nullable|boolean',
            'trusted' => 'nullable|boolean',
            'sort' => 'nullable|in:last_seen,first_seen,votes,posts',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
