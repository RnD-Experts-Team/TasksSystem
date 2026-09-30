<?php

namespace App\Http\Requests\Roadmap\Admin;

use App\Models\Roadmap\Status;
use Illuminate\Validation\Rule;

class UpdateStatusRequest extends AdminRequest
{
    public function rules(): array
    {
        $statusId = (int) $this->route('status');
        $boardId = (int) Status::query()->whereKey($statusId)->value('board_id');

        return [
            'name' => 'sometimes|required|string|max:60',
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:60', Rule::unique('roadmap_statuses', 'slug')->where('board_id', $boardId)->ignore($statusId)],
            'color' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'kind' => 'sometimes|required|in:open,planned,in_progress,done,closed',
            'is_roadmap_column' => 'sometimes|boolean',
            'locks_voting' => 'sometimes|boolean',
        ];
    }
}
