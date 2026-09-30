<?php

namespace App\Http\Requests\Roadmap\Public;

/** GET /changelog */
class ListChangelogRequest extends PublicRequest
{
    public function rules(): array
    {
        return [
            'board' => 'nullable|string|max:64',
            'label' => 'nullable|string|in:new,improved,fixed',
            'page' => 'nullable|integer|min:1|max:10000',
            'per_page' => 'nullable|integer|min:1|max:30',
        ];
    }
}
