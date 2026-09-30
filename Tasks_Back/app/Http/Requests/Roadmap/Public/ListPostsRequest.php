<?php

namespace App\Http\Requests\Roadmap\Public;

/** GET /boards/{board}/posts */
class ListPostsRequest extends PublicRequest
{
    protected function prepareForValidation(): void
    {
        $this->arrayify('status');
        $this->arrayify('tag');
    }

    public function rules(): array
    {
        return [
            'q' => 'nullable|string|max:100',
            'sort' => 'nullable|string|in:top,new,trending',
            'status' => 'nullable|array|max:10',
            'status.*' => 'string|max:60',
            'tag' => 'nullable|array|max:10',
            'tag.*' => 'string|max:40',
            'page' => 'nullable|integer|min:1|max:10000',
            'per_page' => 'nullable|integer|min:1|max:30',
        ];
    }
}
