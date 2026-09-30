<?php

namespace App\Http\Requests\Roadmap\Admin;

class MarkdownPreviewRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'md' => 'nullable|string|max:20000',
        ];
    }
}
