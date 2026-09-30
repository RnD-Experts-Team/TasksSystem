<?php

namespace App\Http\Requests\Roadmap\Admin;

use Illuminate\Validation\Rule;

class StoreTagRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:40',
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:40', Rule::unique('roadmap_tags', 'slug')->where('board_id', (int) $this->route('board'))],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
