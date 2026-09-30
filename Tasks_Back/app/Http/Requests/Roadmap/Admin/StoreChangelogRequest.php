<?php

namespace App\Http\Requests\Roadmap\Admin;

class StoreChangelogRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:140',
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:100', 'unique:roadmap_changelog_entries,slug'],
            'body_md' => 'required|string|max:20000',
            'label' => 'required|in:new,improved,fixed',
            'summary' => 'nullable|string|max:280',
            'published_at' => 'nullable|date',
            'board_id' => 'nullable|integer|exists:roadmap_boards,id',
        ];
    }
}
