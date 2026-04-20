<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Services\TodoService;
use App\Http\Requests\TodoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TodoController extends Controller
{
    protected TodoService $todoService;

    public function __construct(TodoService $todoService)
    {
        $this->todoService = $todoService;
    }

    public function index(int $workspaceId): JsonResponse
    {
        try {
            $this->ensureUserBelongsToWorkspace($workspaceId);

            $todos = $this->todoService->getAllByWorkspace($workspaceId);

            return response()->json([
                'success' => true,
                'data' => $todos
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function store(TodoRequest $request, int $workspaceId): JsonResponse
    {
        try {
            $this->ensureOwner($workspaceId);

            $data = $request->validated();
            $data['workspace_id'] = $workspaceId;

            // optional: if parent_id exists, make sure it belongs to same workspace
            if (!empty($data['parent_id'])) {
                $parentTodo = Todo::find($data['parent_id']);

                if (!$parentTodo || $parentTodo->workspace_id !== $workspaceId) {
                    throw new \Exception('Parent todo does not belong to this workspace');
                }
            }

            $todo = $this->todoService->create($data);

            return response()->json([
                'success' => true,
                'data' => $todo
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show(int $workspaceId, Todo $todo): JsonResponse
    {
        try {
            $this->ensureUserBelongsToWorkspace($workspaceId);
            $this->ensureTodoBelongsToWorkspace($todo, $workspaceId);

            return response()->json([
                'success' => true,
                'data' => $todo->load('subtodos')
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function update(TodoRequest $request, int $workspaceId, Todo $todo): JsonResponse
    {
        try {
            $this->ensureOwner($workspaceId);
            $this->ensureTodoBelongsToWorkspace($todo, $workspaceId);

            $data = $request->validated();

            // prevent changing workspace_id from request
            unset($data['workspace_id']);

            // optional: if parent_id exists, make sure it belongs to same workspace
            if (!empty($data['parent_id'])) {
                $parentTodo = Todo::find($data['parent_id']);

                if (!$parentTodo || $parentTodo->workspace_id !== $workspaceId) {
                    throw new \Exception('Parent todo does not belong to this workspace');
                }
            }

            $updated = $this->todoService->update($todo, $data);

            return response()->json([
                'success' => true,
                'data' => $updated
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(int $workspaceId, Todo $todo): JsonResponse
    {
        try {
            $this->ensureOwner($workspaceId);
            $this->ensureTodoBelongsToWorkspace($todo, $workspaceId);

            $this->todoService->delete($todo);

            return response()->json([
                'success' => true,
                'message' => 'Deleted successfully'
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function ensureUserBelongsToWorkspace(int $workspaceId): void
    {
        $exists = Auth::user()
            ->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->exists();

        if (!$exists) {
            throw new \Exception('Unauthorized access to this workspace');
        }
    }

    private function ensureOwner(int $workspaceId): void
    {
        $role = Auth::user()
            ->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->first()
            ?->pivot
            ?->role;

        if (!in_array($role, ['owner', 'editor'])) {
            throw new \Exception('Only owner and editor can perform this action');
        }
    }

    private function ensureTodoBelongsToWorkspace(Todo $todo, int $workspaceId): void
    {
        if ($todo->workspace_id !== $workspaceId) {
            throw new \Exception('This todo does not belong to this workspace');
        }
    }
}