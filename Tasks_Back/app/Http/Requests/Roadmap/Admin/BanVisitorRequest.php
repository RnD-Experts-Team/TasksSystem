<?php

namespace App\Http\Requests\Roadmap\Admin;

class BanVisitorRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'reason' => 'nullable|string|max:200',
            'remove_content' => 'sometimes|boolean',
            'remove_votes' => 'sometimes|boolean',
        ];
    }
}
