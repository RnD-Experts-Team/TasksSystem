<?php

namespace App\Http\Requests\Roadmap\Admin;

class SetResponseRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'body_md' => 'required|string|max:10000',
        ];
    }
}
