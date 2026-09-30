<?php

namespace App\Http\Requests\Roadmap\Admin;

class DeleteStatusRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'reassign_to_status_id' => 'nullable|integer|exists:roadmap_statuses,id',
        ];
    }
}
