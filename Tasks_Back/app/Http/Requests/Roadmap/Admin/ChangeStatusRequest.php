<?php

namespace App\Http\Requests\Roadmap\Admin;

class ChangeStatusRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'status_id' => 'required|integer|exists:roadmap_statuses,id',
            'note' => 'nullable|string|max:500',
            'note_is_public' => 'sometimes|boolean',
        ];
    }
}
