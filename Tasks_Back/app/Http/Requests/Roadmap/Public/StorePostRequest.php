<?php

namespace App\Http\Requests\Roadmap\Public;

use App\Support\Roadmap\TextSanitizer;
use Closure;

/**
 * POST /boards/{board}/posts
 *
 * Text is sanitised BEFORE validation so length rules count what will really be stored.
 * The honeypot field `website` is intentionally not validated (it is read by the controller):
 * a bot must get a fake success, not a validation hint.
 */
class StorePostRequest extends PublicRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('title'))) {
            $merge['title'] = TextSanitizer::clean($this->input('title'));
        }
        if (is_string($this->input('body'))) {
            $body = TextSanitizer::clean($this->input('body'), true);
            $merge['body'] = $body === '' ? null : $body;
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
            'title' => 'required|string|min:8|max:140',
            'body' => 'nullable|string|max:5000',
            'author_name' => [
                'nullable', 'string', 'max:40',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && ($error = TextSanitizer::authorNameError($value)) !== null) {
                        $fail($error);
                    }
                },
            ],
            'tag_slugs' => 'nullable|array|max:3',
            'tag_slugs.*' => 'string|max:40',
            'form_token' => 'required|string|max:600',
        ];
    }
}
