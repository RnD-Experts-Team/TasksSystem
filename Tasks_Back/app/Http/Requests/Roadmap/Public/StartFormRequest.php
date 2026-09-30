<?php

namespace App\Http\Requests\Roadmap\Public;

/** POST /forms/{kind}/start  body { board: slug } */
class StartFormRequest extends PublicRequest
{
    public function rules(): array
    {
        return [
            'board' => 'required|string|max:64',
        ];
    }
}
