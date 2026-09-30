<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Http\Requests\Roadmap\Public\SetVoteRequest;
use App\Services\Roadmap\VoteService;
use App\Support\Roadmap\IpHasher;
use Illuminate\Http\JsonResponse;

class VoteController extends PublicApiController
{
    public function __construct(private VoteService $votes) {}

    /** POST /boards/{board}/posts/{number}/vote  { voted } → { voted, votes_count } */
    public function set(SetVoteRequest $request, string $board, int $number): JsonResponse
    {
        $result = $this->votes->set(
            $board,
            $number,
            $this->visitor($request),
            $request->boolean('voted'),
            IpHasher::forRequest($request),
        );

        return $this->ok($result, 'Vote saved');
    }
}
