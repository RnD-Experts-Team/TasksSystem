<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Http\Requests\Roadmap\Public\ListCommentsRequest;
use App\Http\Requests\Roadmap\Public\StoreCommentRequest;
use App\Http\Resources\Roadmap\Public\PublicCommentResource;
use App\Models\Roadmap\Comment;
use App\Services\Roadmap\CommentService;
use App\Support\Roadmap\IpHasher;
use App\Support\Roadmap\Limits;
use Illuminate\Http\JsonResponse;

class CommentController extends PublicApiController
{
    public function __construct(private CommentService $comments) {}

    /** GET /boards/{board}/posts/{number}/comments */
    public function index(ListCommentsRequest $request, string $board, int $number): JsonResponse
    {
        $result = $this->comments->list($board, $number, (int) ($request->validated()['page'] ?? 1));

        $team = Limits::teamName();
        $items = $result['paginator']->getCollection()->map(fn (Comment $comment) => (new PublicCommentResource(
            $comment,
            $team,
            $result['replies']->get($comment->id, collect()),
        ))->resolve())->values()->all();

        return $this->paginated($result['paginator'], $items, 'Comments retrieved successfully');
    }

    /** POST /boards/{board}/posts/{number}/comments */
    public function store(StoreCommentRequest $request, string $board, int $number): JsonResponse
    {
        $result = $this->comments->submit(
            $board,
            $number,
            $this->visitor($request),
            $request->validated(),
            (string) $request->input('website', ''),
            IpHasher::forRequest($request),
        );

        return $this->ok(
            $result,
            $result['moderation_state'] === 'approved' ? 'Comment posted.' : 'Thanks! Your comment is awaiting review.',
            201
        );
    }
}
