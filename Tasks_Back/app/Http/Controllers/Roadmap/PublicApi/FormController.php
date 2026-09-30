<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Http\Requests\Roadmap\Public\StartFormRequest;
use App\Services\Roadmap\BoardService;
use App\Services\Roadmap\FormTokenService;
use Illuminate\Http\JsonResponse;

class FormController extends PublicApiController
{
    public function __construct(private BoardService $boards, private FormTokenService $tokens) {}

    /** POST /forms/{kind}/start  { board } → { form_token, min_seconds, max_age_seconds } */
    public function start(StartFormRequest $request, string $kind): JsonResponse
    {
        $board = $this->boards->findBySlug($request->validated()['board']);

        return $this->ok($this->tokens->issue($kind, $board, $this->visitor($request)), 'Form ready');
    }
}
