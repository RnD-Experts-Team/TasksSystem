<?php

namespace App\Http\Requests\Roadmap\Admin;

use Illuminate\Validation\Rule;

class UpdateChangelogRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:140',
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:100', Rule::unique('roadmap_changelog_entries', 'slug')->ignore((int) $this->route('entry'))],
            'body_md' => 'sometimes|required|string|max:20000',
            'label' => 'sometimes|required|in:new,improved,fixed',
            'summary' => 'nullable|string|max:280',
            'published_at' => 'nullable|date',
            'board_id' => 'nullable|integer|exists:roadmap_boards,id',
        ];
    }
}
