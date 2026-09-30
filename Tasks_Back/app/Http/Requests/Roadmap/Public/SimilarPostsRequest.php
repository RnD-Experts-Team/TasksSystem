<?php

namespace App\Http\Requests\Roadmap\Public;

use App\Support\Roadmap\TextSanitizer;

/** GET /boards/{board}/posts/similar?q= */
class SimilarPostsRequest extends PublicRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('q'))) {
            $this->merge(['q' => TextSanitizer::clean($this->query('q'))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => 'required|string|min:3|max:140',
        ];
    }
}
