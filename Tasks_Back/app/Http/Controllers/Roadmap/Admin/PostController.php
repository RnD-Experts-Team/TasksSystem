<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\BulkModerateRequest;
use App\Http\Requests\Roadmap\Admin\ChangeStatusRequest;
use App\Http\Requests\Roadmap\Admin\MergePostRequest;
use App\Http\Requests\Roadmap\Admin\ModerateRequest;
use App\Http\Requests\Roadmap\Admin\MovePostRequest;
use App\Http\Requests\Roadmap\Admin\PinPostRequest;
use App\Http\Requests\Roadmap\Admin\PostIndexRequest;
use App\Http\Requests\Roadmap\Admin\RoadmapMoveRequest;
use App\Http\Requests\Roadmap\Admin\SetResponseRequest;
use App\Http\Requests\Roadmap\Admin\StorePostRequest;
use App\Http\Requests\Roadmap\Admin\SyncTagsRequest;
use App\Http\Requests\Roadmap\Admin\UpdatePostRequest;
use App\Http\Resources\Roadmap\Admin\AdminPostDetailResource;
use App\Http\Resources\Roadmap\Admin\AdminPostResource;
use App\Http\Resources\Roadmap\Admin\AdminRoadmapResource;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Post;
use App\Services\Roadmap\AdminPostService;
use App\Services\Roadmap\MergeService;
use App\Services\Roadmap\ModerationService;
use Illuminate\Http\JsonResponse;

/**
 * Admin posts. Reads and moderation are open to "manage roadmap" OR "moderate roadmap";
 * everything else needs "manage roadmap" (enforced by the route groups).
 * Every mutating endpoint answers with the refreshed AdminPostDetail.
 */
class PostController extends AdminController
{
    public function __construct(
        private AdminPostService $posts,
        private ModerationService $moderation,
        private MergeService $merges,
    ) {}

    // ─── Reads ───────────────────────────────────────────────────────

    public function index(PostIndexRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $page = $this->posts->paginate($request->validated());

            return $this->paginated(
                $page,
                AdminPostResource::collection($page->getCollection())->resolve(),
                'Posts retrieved successfully'
            );
        });
    }

    public function show(int $post): JsonResponse
    {
        return $this->run(fn () => $this->detail($post, 'Post retrieved successfully'));
    }

    // ─── Create / edit / delete ──────────────────────────────────────

    public function store(StorePostRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $created = $this->posts->create($request->validated(), $request->user());

            return $this->detail($created->id, 'Post created successfully', 201);
        });
    }

    public function update(UpdatePostRequest $request, int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) use ($request) {
            $updated = $this->posts->update($model, $request->validated());

            return $this->detail($updated->id, 'Post updated successfully');
        });
    }

    public function destroy(int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) {
            $this->posts->delete($model);

            return $this->ok(null, 'Post deleted successfully');
        });
    }

    // ─── Moderation ──────────────────────────────────────────────────

    public function moderate(ModerateRequest $request, int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) use ($request) {
            $data = $request->validated();
            $this->moderation->moderatePost($model, $data['state'], $data['reason'] ?? null, $request->user());

            return $this->detail($model->id, 'Post moderated successfully');
        });
    }

    public function bulkModerate(BulkModerateRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validated();
            $updated = $this->moderation->bulkModeratePosts($data['ids'], $data['state'], $request->user());

            return $this->ok(['updated' => $updated], 'Posts moderated successfully');
        });
    }

    // ─── Status / response / pin ─────────────────────────────────────

    public function status(ChangeStatusRequest $request, int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) use ($request) {
            $data = $request->validated();
            $this->posts->changeStatus($model, (int) $data['status_id'], $data['note'] ?? null, (bool) ($data['note_is_public'] ?? true), $request->user());

            return $this->detail($model->id, 'Status changed successfully');
        });
    }

    public function setResponse(SetResponseRequest $request, int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) use ($request) {
            $this->posts->setResponse($model, $request->validated('body_md'), $request->user());

            return $this->detail($model->id, 'Official response saved successfully');
        });
    }

    public function clearResponse(int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) {
            $this->posts->clearResponse($model);

            return $this->detail($model->id, 'Official response removed successfully');
        });
    }

    public function pin(PinPostRequest $request, int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) use ($request) {
            $pinned = $request->validated('pinned');
            $this->posts->pin($model, $pinned === null ? null : (bool) $pinned);

            return $this->detail($model->id, 'Post updated successfully');
        });
    }

    // ─── Merge / move / tags ─────────────────────────────────────────

    public function mergePreview(MergePostRequest $request, int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) use ($request) {
            $target = Post::query()->findOrFail((int) $request->validated('target_post_id'));

            return $this->ok($this->merges->preview($model, $target), 'Merge preview generated successfully');
        });
    }

    public function merge(MergePostRequest $request, int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) use ($request) {
            $target = Post::query()->findOrFail((int) $request->validated('target_post_id'));
            $merged = $this->merges->merge($model, $target);

            return $this->detail($merged->id, 'Posts merged successfully');
        });
    }

    public function move(MovePostRequest $request, int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) use ($request) {
            $data = $request->validated();
            $this->posts->move($model, (int) $data['board_id'], isset($data['status_id']) ? (int) $data['status_id'] : null);

            return $this->detail($model->id, 'Post moved successfully');
        });
    }

    public function syncTags(SyncTagsRequest $request, int $post): JsonResponse
    {
        return $this->withPost($post, function (Post $model) use ($request) {
            $this->posts->syncTags($model, $request->validated('tag_ids'));

            return $this->detail($model->id, 'Tags updated successfully');
        });
    }

    // ─── Roadmap kanban ──────────────────────────────────────────────

    public function roadmap(int $board): JsonResponse
    {
        return $this->run(function () use ($board) {
            $model = Board::query()->find($board);

            return $model
                ? $this->ok((new AdminRoadmapResource($this->posts->roadmap($model)))->resolve(), 'Roadmap retrieved successfully')
                : $this->notFound('Board not found');
        });
    }

    public function roadmapMove(RoadmapMoveRequest $request, int $board): JsonResponse
    {
        return $this->run(function () use ($request, $board) {
            $model = Board::query()->find($board);
            if (! $model) {
                return $this->notFound('Board not found');
            }
            $data = $request->validated();
            $result = $this->posts->roadmapMove($model, (int) $data['post_id'], (int) $data['to_status_id'], $data['ordered_ids'], $request->user());

            return $this->ok((new AdminRoadmapResource($result))->resolve(), 'Roadmap updated successfully');
        });
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function withPost(int $id, callable $action): JsonResponse
    {
        return $this->run(function () use ($id, $action) {
            $model = Post::query()->find($id);

            return $model ? $action($model) : $this->notFound('Post not found');
        });
    }

    private function detail(int $id, string $message, int $status = 200): JsonResponse
    {
        $post = $this->posts->findDetailed($id);

        return $post
            ? $this->ok((new AdminPostDetailResource($post))->resolve(), $message, $status)
            : $this->notFound('Post not found');
    }
}
