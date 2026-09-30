<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\BulkModerateRequest;
use App\Http\Requests\Roadmap\Admin\CommentIndexRequest;
use App\Http\Requests\Roadmap\Admin\ModerateRequest;
use App\Http\Requests\Roadmap\Admin\ReplyCommentRequest;
use App\Http\Resources\Roadmap\Admin\AdminCommentResource;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Services\Roadmap\ModerationService;
use Illuminate\Http\JsonResponse;

class CommentController extends AdminController
{
    public function __construct(private ModerationService $moderation) {}

    public function index(CommentIndexRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $filters = $request->validated();
            $state = $filters['moderation_state'] ?? 'all';

            $page = Comment::query()
                ->with(['post.board:id,slug', 'visitor'])
                ->when($state !== 'all', fn ($q) => $q->where('moderation_state', $state))
                ->when(! empty($filters['post_id']), fn ($q) => $q->where('post_id', (int) $filters['post_id']))
                ->when(! empty($filters['visitor_id']), fn ($q) => $q->where('visitor_id', $filters['visitor_id']))
                ->orderByDesc('id')
                ->paginate((int) ($filters['per_page'] ?? 25));

            return $this->paginated($page, AdminCommentResource::collection($page->getCollection())->resolve(), 'Comments retrieved successfully');
        });
    }

    public function moderate(ModerateRequest $request, int $comment): JsonResponse
    {
        return $this->run(function () use ($request, $comment) {
            $model = Comment::query()->find($comment);
            if (! $model) {
                return $this->notFound('Comment not found');
            }

            $data = $request->validated();
            $updated = $this->moderation->moderateComment($model, $data['state'], $data['reason'] ?? null, $request->user());

            return $this->ok($this->resource($updated), 'Comment moderated successfully');
        });
    }

    public function bulkModerate(BulkModerateRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validated();
            $updated = $this->moderation->bulkModerateComments($data['ids'], $data['state'], $request->user());

            return $this->ok(['updated' => $updated], 'Comments moderated successfully');
        });
    }

    public function destroy(int $comment): JsonResponse
    {
        return $this->run(function () use ($comment) {
            $model = Comment::query()->find($comment);
            if (! $model) {
                return $this->notFound('Comment not found');
            }
            $this->moderation->deleteComment($model);

            return $this->ok(null, 'Comment deleted successfully');
        });
    }

    /** Official reply from the team (shown publicly as the team, never with the admin's name). */
    public function reply(ReplyCommentRequest $request, int $post): JsonResponse
    {
        return $this->run(function () use ($request, $post) {
            $model = Post::query()->find($post);
            if (! $model) {
                return $this->notFound('Post not found');
            }

            $data = $request->validated();
            $comment = $this->moderation->replyAsTeam($model, $data['body'], isset($data['parent_id']) ? (int) $data['parent_id'] : null, $request->user());

            return $this->ok($this->resource($comment), 'Reply posted successfully', 201);
        });
    }

    private function resource(Comment $comment): array
    {
        return (new AdminCommentResource($comment->load(['post.board:id,slug', 'visitor'])))->resolve();
    }
}
