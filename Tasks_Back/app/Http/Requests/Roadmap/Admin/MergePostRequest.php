<?php

namespace App\Http\Requests\Roadmap\Admin;

class MergePostRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'target_post_id' => 'required|integer|exists:roadmap_posts,id',
        ];
    }
}
