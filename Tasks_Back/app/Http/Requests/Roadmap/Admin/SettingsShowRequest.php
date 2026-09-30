<?php

namespace App\Http\Requests\Roadmap\Admin;

class SettingsShowRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'regex:/^(global|board:[0-9]+)$/'],
        ];
    }
}
