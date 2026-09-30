<?php

namespace App\Http\Requests\Roadmap\Admin;

class MovePostRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'board_id' => 'required|integer|exists:roadmap_boards,id',
            'status_id' => 'nullable|integer|exists:roadmap_statuses,id',
        ];
    }
}
