<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\ReorderRequest;
use App\Http\Requests\Roadmap\Admin\StoreBoardRequest;
use App\Http\Requests\Roadmap\Admin\UpdateBoardRequest;
use App\Http\Resources\Roadmap\Admin\AdminBoardResource;
use App\Models\Roadmap\Board;
use App\Services\Roadmap\BoardAdminService;
use Illuminate\Http\JsonResponse;

class BoardController extends AdminController
{
    public function __construct(private BoardAdminService $boards) {}

    public function index(): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            AdminBoardResource::collection($this->boards->list())->resolve(),
            'Boards retrieved successfully'
        ));
    }

    public function store(StoreBoardRequest $request): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            (new AdminBoardResource($this->boards->create($request->validated())))->resolve(),
            'Board created successfully',
            201
        ));
    }

    public function show(int $board): JsonResponse
    {
        return $this->run(function () use ($board) {
            $found = $this->boards->find($board);

            return $found
                ? $this->ok((new AdminBoardResource($found))->resolve(), 'Board retrieved successfully')
                : $this->notFound('Board not found');
        });
    }

    public function update(UpdateBoardRequest $request, int $board): JsonResponse
    {
        return $this->run(function () use ($request, $board) {
            $model = Board::query()->find($board);
            if (! $model) {
                return $this->notFound('Board not found');
            }

            return $this->ok(
                (new AdminBoardResource($this->boards->update($model, $request->validated())))->resolve(),
                'Board updated successfully'
            );
        });
    }

    public function destroy(int $board): JsonResponse
    {
        return $this->run(function () use ($board) {
            $model = Board::query()->find($board);
            if (! $model) {
                return $this->notFound('Board not found');
            }
            $this->boards->delete($model);

            return $this->ok(null, 'Board deleted successfully');
        });
    }

    public function reorder(ReorderRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $this->boards->reorder($request->validated('ids'));

            return $this->ok(null, 'Boards reordered successfully');
        });
    }
}
