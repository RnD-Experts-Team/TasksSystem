<?php

namespace App\Http\Requests\Roadmap\Admin;

class ChangelogIndexRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'status' => 'nullable|in:all,draft,published',
            'board_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ];
    }
}
