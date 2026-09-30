<?php

namespace App\Http\Requests\Roadmap\Admin;

/** Body of every `reorder` endpoint: the full list of ids in the new order. */
class ReorderRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
        ];
    }
}
