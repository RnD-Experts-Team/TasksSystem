<?php

namespace App\Http\Requests\Roadmap\Public;

use App\Support\Roadmap\TextSanitizer;
use Closure;

/** POST /boards/{board}/posts/{number}/comments (honeypot `website` is read by the controller). */
class StoreCommentRequest extends PublicRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('body'))) {
            $merge['body'] = TextSanitizer::clean($this->input('body'), true);
        }
        if (is_string($this->input('author_name'))) {
            $name = TextSanitizer::clean($this->input('author_name'));
            $merge['author_name'] = $name === '' ? null : $name;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'body' => 'required|string|min:2|max:2000',
            'author_name' => [
                'nullable', 'string', 'max:40',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && ($error = TextSanitizer::authorNameError($value)) !== null) {
                        $fail($error);
                    }
                },
            ],
            'parent_id' => 'nullable|integer|min:1',
            'form_token' => 'required|string|max:600',
        ];
    }
}
