<?php

namespace App\Http\Requests\Roadmap\Admin;

use App\Models\Roadmap\Tag;
use Illuminate\Validation\Rule;

class UpdateTagRequest extends AdminRequest
{
    public function rules(): array
    {
        $tagId = (int) $this->route('tag');
        $boardId = (int) Tag::query()->whereKey($tagId)->value('board_id');

        return [
            'name' => 'sometimes|required|string|max:40',
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:40', Rule::unique('roadmap_tags', 'slug')->where('board_id', $boardId)->ignore($tagId)],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
