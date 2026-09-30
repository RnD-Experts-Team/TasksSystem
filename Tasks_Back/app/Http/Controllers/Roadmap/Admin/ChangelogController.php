<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\ChangelogIndexRequest;
use App\Http\Requests\Roadmap\Admin\PublishChangelogRequest;
use App\Http\Requests\Roadmap\Admin\StoreChangelogRequest;
use App\Http\Requests\Roadmap\Admin\SyncChangelogPostsRequest;
use App\Http\Requests\Roadmap\Admin\UpdateChangelogRequest;
use App\Http\Resources\Roadmap\Admin\AdminChangelogResource;
use App\Models\Roadmap\ChangelogEntry;
use App\Services\Roadmap\ChangelogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangelogController extends AdminController
{
    public function __construct(private ChangelogService $changelog) {}

    public function index(ChangelogIndexRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $page = $this->changelog->paginate($request->validated());

            return $this->paginated(
                $page,
                AdminChangelogResource::collection($page->getCollection())->resolve(),
                'Changelog retrieved successfully'
            );
        });
    }

    public function store(StoreChangelogRequest $request): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            $this->detail($this->changelog->create($request->validated(), $request->user())),
            'Changelog entry created successfully',
            201
        ));
    }

    public function show(int $entry): JsonResponse
    {
        return $this->withEntry($entry, fn (ChangelogEntry $e) => $this->ok(
            $this->detail($this->changelog->find($e->id)),
            'Changelog entry retrieved successfully'
        ));
    }

    public function update(UpdateChangelogRequest $request, int $entry): JsonResponse
    {
        return $this->withEntry($entry, fn (ChangelogEntry $e) => $this->ok(
            $this->detail($this->changelog->update($e, $request->validated(), $request->user())),
            'Changelog entry updated successfully'
        ));
    }

    public function destroy(int $entry): JsonResponse
    {
        return $this->withEntry($entry, function (ChangelogEntry $e) {
            $this->changelog->delete($e);

            return $this->ok(null, 'Changelog entry deleted successfully');
        });
    }

    public function publish(PublishChangelogRequest $request, int $entry): JsonResponse
    {
        return $this->withEntry($entry, fn (ChangelogEntry $e) => $this->ok(
            $this->detail($this->changelog->publish($e, $request->validated('published_at'), $request->user())),
            'Changelog entry published successfully'
        ));
    }

    public function unpublish(Request $request, int $entry): JsonResponse
    {
        return $this->withEntry($entry, fn (ChangelogEntry $e) => $this->ok(
            $this->detail($this->changelog->unpublish($e, $request->user())),
            'Changelog entry moved to drafts'
        ));
    }

    public function syncPosts(SyncChangelogPostsRequest $request, int $entry): JsonResponse
    {
        return $this->withEntry($entry, function (ChangelogEntry $e) use ($request) {
            $data = $request->validated();
            $updated = $this->changelog->syncPosts($e, $data['post_ids'], isset($data['mark_status_id']) ? (int) $data['mark_status_id'] : null, $request->user());

            return $this->ok($this->detail($updated), 'Linked posts updated successfully');
        });
    }

    private function withEntry(int $id, callable $action): JsonResponse
    {
        return $this->run(function () use ($id, $action) {
            $model = ChangelogEntry::query()->find($id);

            return $model ? $action($model) : $this->notFound('Changelog entry not found');
        });
    }

    /** @return array<string, mixed> */
    private function detail(ChangelogEntry $entry): array
    {
        return (new AdminChangelogResource($entry->loadMissing(['board:id,slug,name', 'posts.board:id,slug']), true))->resolve();
    }
}
