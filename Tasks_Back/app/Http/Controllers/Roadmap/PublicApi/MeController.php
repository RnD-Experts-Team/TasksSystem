<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Http\Requests\Roadmap\Public\MeStateRequest;
use App\Models\Roadmap\Board;
use App\Services\Roadmap\VisitorService;
use Illuminate\Http\JsonResponse;

class MeController extends PublicApiController
{
    public function __construct(private VisitorService $visitors) {}

    /** GET /me/state?board= : per-visitor state, never cached, never an error for unknown tokens. */
    public function state(MeStateRequest $request): JsonResponse
    {
        $slug = $request->validated()['board'] ?? null;
        $board = $slug !== null
            ? Board::query()->where('slug', $slug)->where('is_archived', false)->first()
            : null;

        $response = $this->ok($this->visitors->state($this->visitor($request), $board), 'State retrieved successfully');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
