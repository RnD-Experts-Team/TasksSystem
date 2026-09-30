<?php

namespace App\Http\Requests\Roadmap\Admin;

/** Body of the single moderate endpoints (posts and comments). */
class ModerateRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'state' => 'required|in:pending,approved,rejected,spam',
            'reason' => 'nullable|string|max:200',
        ];
    }
}
