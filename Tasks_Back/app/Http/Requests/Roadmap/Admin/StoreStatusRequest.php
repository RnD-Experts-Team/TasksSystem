<?php

namespace App\Http\Requests\Roadmap\Admin;

use Illuminate\Validation\Rule;

class StoreStatusRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:60',
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:60', Rule::unique('roadmap_statuses', 'slug')->where('board_id', (int) $this->route('board'))],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'kind' => 'required|in:open,planned,in_progress,done,closed',
            'is_roadmap_column' => 'sometimes|boolean',
            'locks_voting' => 'sometimes|boolean',
        ];
    }
}
