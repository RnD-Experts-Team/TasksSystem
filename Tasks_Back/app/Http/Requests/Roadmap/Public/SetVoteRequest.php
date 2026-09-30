<?php

namespace App\Http\Requests\Roadmap\Public;

/** POST /boards/{board}/posts/{number}/vote  body { voted: bool } */
class SetVoteRequest extends PublicRequest
{
    public function rules(): array
    {
        return [
            'voted' => 'required|boolean',
        ];
    }
}
