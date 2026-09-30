<?php

namespace App\Http\Requests\Roadmap\Admin;

class SyncChangelogPostsRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'post_ids' => 'present|array|max:100',
            'post_ids.*' => 'integer',
            'mark_status_id' => 'nullable|integer|exists:roadmap_statuses,id',
        ];
    }
}
