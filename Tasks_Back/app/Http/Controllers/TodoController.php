<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Models\Workspace;
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

    public function store(TodoRequest $request): JsonResponse
    {
        try {

            $this->ensureOwner($request->workspace_id);

            $todo = $this->todoService->create($request->validated());

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

    public function show(Todo $todo): JsonResponse
    {
        try {

            $this->ensureOwner($todo->workspace_id);

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

    public function update(TodoRequest $request, Todo $todo): JsonResponse
    {
        try {

            $this->ensureOwner($todo->workspace_id);

            $updated = $this->todoService->update($todo, $request->validated());

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

    public function destroy(Todo $todo): JsonResponse
    {
        try {

            $this->ensureOwner($todo->workspace);

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
    private function ensureOwner(Workspace $workspace): void
    {
        $role = Auth::user()
            ->workspaces()
            ->where('workspace_id', $workspace->id)
            ->first()
            ?->pivot
            ?->role;

          if (!in_array($role, ['owner', 'editor'])) {
            throw new \Exception('Only owner and editor can perform this action');
        }
    }
}
