<?php

namespace App\Http\Requests\Roadmap\Admin;

/** Optional desired state; without it the pin is toggled. */
class PinPostRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'pinned' => 'nullable|boolean',
        ];
    }
}
