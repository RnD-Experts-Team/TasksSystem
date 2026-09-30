<?php

namespace App\Http\Requests\Roadmap\Admin;

class UpdatePostRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|min:3|max:140',
            'body' => 'nullable|string|max:5000',
            'author_name' => 'nullable|string|max:40',
        ];
    }
}
