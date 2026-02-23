<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workspace_id' => 'required|exists:workspaces,id',
            'parent_id' => 'nullable|exists:todos,id',
            'title' => 'required|string|max:255',
            'status' => 'nullable|in:pending,inprogress,completed',
            'due_date' => 'nullable|date',
        ];
    }
}
