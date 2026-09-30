<?php

namespace App\Http\Requests\Roadmap\Public;

/** GET /me/state?board= */
class MeStateRequest extends PublicRequest
{
    public function rules(): array
    {
        return [
            'board' => 'nullable|string|max:64',
        ];
    }
}
