<?php

namespace App\Http\Requests\Roadmap\Admin;

class StoreBoardRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:80',
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:64', 'unique:roadmap_boards,slug', 'not_in:p,roadmap,changelog,feed,new,me,api,admin'],
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:32',
            'is_archived' => 'sometimes|boolean',
            'voting_mode' => 'sometimes|in:anonymous,verified_email',
            'allow_submissions' => 'sometimes|boolean',
            'allow_comments' => 'sometimes|boolean',
            'allow_votes' => 'sometimes|boolean',
            'require_post_approval' => 'sometimes|boolean',
            'require_comment_approval' => 'sometimes|boolean',
            'trust_after_approved' => 'nullable|integer|min:1|max:50',
        ];
    }
}
