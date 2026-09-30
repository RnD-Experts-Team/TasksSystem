<?php

namespace App\Http\Requests\Roadmap\Admin;

class StorePostRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'board_id' => 'required|integer|exists:roadmap_boards,id',
            'title' => 'required|string|min:3|max:140',
            'body' => 'nullable|string|max:5000',
            'status_id' => 'nullable|integer|exists:roadmap_statuses,id',
            'tag_ids' => 'nullable|array|max:10',
            'tag_ids.*' => 'integer',
        ];
    }
}
