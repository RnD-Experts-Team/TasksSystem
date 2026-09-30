<?php

namespace App\Http\Requests\Roadmap\Admin;

/** Body of the bulk-moderate endpoints (posts and comments): at most 100 ids. */
class BulkModerateRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
            'state' => 'required|in:pending,approved,rejected,spam',
        ];
    }
}
