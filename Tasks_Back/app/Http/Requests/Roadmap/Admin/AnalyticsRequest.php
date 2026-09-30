<?php

namespace App\Http\Requests\Roadmap\Admin;

class AnalyticsRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'board_id' => 'nullable|integer|exists:roadmap_boards,id',
            'days' => 'nullable|integer|min:1|max:365',
        ];
    }
}
