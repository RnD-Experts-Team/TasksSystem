<?php

namespace App\Http\Requests\Roadmap\Admin;

class BulkRemoveRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'by' => 'required|in:visitor,ip_hash',
            'value' => 'required|string|max:64|regex:/^[0-9A-Za-z]+$/',
            'remove' => 'required|array|min:1',
            'remove.*' => 'in:votes,posts,comments',
            'ban' => 'sometimes|boolean',
            'reason' => 'nullable|string|max:200',
        ];
    }
}
