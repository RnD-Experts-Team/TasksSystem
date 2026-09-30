<?php

namespace App\Http\Requests\Roadmap\Admin;

class ReplyCommentRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'body' => 'required|string|min:1|max:2000',
            'parent_id' => 'nullable|integer',
        ];
    }
}
