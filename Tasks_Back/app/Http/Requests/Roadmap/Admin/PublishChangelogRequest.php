<?php

namespace App\Http\Requests\Roadmap\Admin;

class PublishChangelogRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'published_at' => 'nullable|date',
        ];
    }
}
