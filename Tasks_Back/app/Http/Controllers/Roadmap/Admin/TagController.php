<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\ReorderRequest;
use App\Http\Requests\Roadmap\Admin\StoreTagRequest;
use App\Http\Requests\Roadmap\Admin\UpdateTagRequest;
use App\Http\Resources\Roadmap\Admin\AdminTagResource;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Tag;
use App\Services\Roadmap\TagService;
use Illuminate\Http\JsonResponse;

class TagController extends AdminController
{
    public function __construct(private TagService $tags) {}

    public function index(int $board): JsonResponse
    {
        return $this->run(function () use ($board) {
            $model = Board::query()->find($board);

            return $model
                ? $this->ok(AdminTagResource::collection($this->tags->list($model))->resolve(), 'Tags retrieved successfully')
                : $this->notFound('Board not found');
        });
    }

    public function store(StoreTagRequest $request, int $board): JsonResponse
    {
        return $this->run(function () use ($request, $board) {
            $model = Board::query()->find($board);
            if (! $model) {
                return $this->notFound('Board not found');
            }

            return $this->ok(
                (new AdminTagResource($this->tags->create($model, $request->validated())))->resolve(),
                'Tag created successfully',
                201
            );
        });
    }

    public function update(UpdateTagRequest $request, int $tag): JsonResponse
    {
        return $this->run(function () use ($request, $tag) {
            $model = Tag::query()->find($tag);
            if (! $model) {
                return $this->notFound('Tag not found');
            }

            return $this->ok(
                (new AdminTagResource($this->tags->update($model, $request->validated())))->resolve(),
                'Tag updated successfully'
            );
        });
    }

    public function destroy(int $tag): JsonResponse
    {
        return $this->run(function () use ($tag) {
            $model = Tag::query()->find($tag);
            if (! $model) {
                return $this->notFound('Tag not found');
            }
            $this->tags->delete($model);

            return $this->ok(null, 'Tag deleted successfully');
        });
    }

    public function reorder(ReorderRequest $request, int $board): JsonResponse
    {
        return $this->run(function () use ($request, $board) {
            $model = Board::query()->find($board);
            if (! $model) {
                return $this->notFound('Board not found');
            }
            $this->tags->reorder($model, $request->validated('ids'));

            return $this->ok(null, 'Tags reordered successfully');
        });
    }
}
