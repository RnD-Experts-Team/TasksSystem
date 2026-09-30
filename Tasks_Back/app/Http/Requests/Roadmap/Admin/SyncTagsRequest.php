<?php

namespace App\Http\Requests\Roadmap\Admin;

class SyncTagsRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'tag_ids' => 'present|array|max:10',
            'tag_ids.*' => 'integer',
        ];
    }
}
