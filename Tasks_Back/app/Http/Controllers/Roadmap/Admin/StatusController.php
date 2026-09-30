<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\DeleteStatusRequest;
use App\Http\Requests\Roadmap\Admin\ReorderRequest;
use App\Http\Requests\Roadmap\Admin\StoreStatusRequest;
use App\Http\Requests\Roadmap\Admin\UpdateStatusRequest;
use App\Http\Resources\Roadmap\Admin\AdminStatusResource;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Status;
use App\Services\Roadmap\StatusService;
use Illuminate\Http\JsonResponse;

class StatusController extends AdminController
{
    public function __construct(private StatusService $statuses) {}

    public function index(int $board): JsonResponse
    {
        return $this->run(function () use ($board) {
            $model = Board::query()->find($board);

            return $model
                ? $this->ok(AdminStatusResource::collection($this->statuses->list($model))->resolve(), 'Statuses retrieved successfully')
                : $this->notFound('Board not found');
        });
    }

    public function store(StoreStatusRequest $request, int $board): JsonResponse
    {
        return $this->run(function () use ($request, $board) {
            $model = Board::query()->find($board);
            if (! $model) {
                return $this->notFound('Board not found');
            }

            return $this->ok(
                (new AdminStatusResource($this->statuses->create($model, $request->validated())))->resolve(),
                'Status created successfully',
                201
            );
        });
    }

    public function update(UpdateStatusRequest $request, int $status): JsonResponse
    {
        return $this->run(function () use ($request, $status) {
            $model = Status::query()->find($status);
            if (! $model) {
                return $this->notFound('Status not found');
            }

            return $this->ok(
                (new AdminStatusResource($this->statuses->update($model, $request->validated())))->resolve(),
                'Status updated successfully'
            );
        });
    }

    public function destroy(DeleteStatusRequest $request, int $status): JsonResponse
    {
        return $this->run(function () use ($request, $status) {
            $model = Status::query()->find($status);
            if (! $model) {
                return $this->notFound('Status not found');
            }

            $reassign = $request->validated('reassign_to_status_id');
            $this->statuses->delete($model, $reassign !== null ? (int) $reassign : null);

            return $this->ok(null, 'Status deleted successfully');
        });
    }

    public function reorder(ReorderRequest $request, int $board): JsonResponse
    {
        return $this->run(function () use ($request, $board) {
            $model = Board::query()->find($board);
            if (! $model) {
                return $this->notFound('Board not found');
            }
            $this->statuses->reorder($model, $request->validated('ids'));

            return $this->ok(null, 'Statuses reordered successfully');
        });
    }

    public function makeDefault(int $board, int $status): JsonResponse
    {
        return $this->run(function () use ($board, $status) {
            $model = Status::query()->where('board_id', $board)->find($status);
            if (! $model) {
                return $this->notFound('Status not found');
            }

            return $this->ok(
                (new AdminStatusResource($this->statuses->makeDefault($model)))->resolve(),
                'Default status updated successfully'
            );
        });
    }
}
