<?php

namespace App\Http\Requests\Roadmap\Public;

/** GET /boards/{board}/posts/{number}/comments */
class ListCommentsRequest extends PublicRequest
{
    public function rules(): array
    {
        return [
            'page' => 'nullable|integer|min:1|max:10000',
        ];
    }
}
