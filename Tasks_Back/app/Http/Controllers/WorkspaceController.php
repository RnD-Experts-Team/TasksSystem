<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\WorkspaceService;
use App\Http\Requests\WorkspaceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Request;

class WorkspaceController extends Controller
{
    protected WorkspaceService $workspaceService;

    public function __construct(WorkspaceService $workspaceService)
    {
        $this->workspaceService = $workspaceService;
    }

    public function index(): JsonResponse
    {
        try {

            $workspaces = $this->workspaceService->getAll();

            return response()->json([
                'success' => true,
                'data' => $workspaces
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch workspaces'
            ], 500);
        }
    }
 
    public function store(WorkspaceRequest $request): JsonResponse
    {
        try {

            $workspace = $this->workspaceService->create($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Workspace created successfully',
                'data' => $workspace
            ], 201);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to create workspace'
            ], 500);
        }
    }

    public function show(Workspace $workspace): JsonResponse
    {
        try {

            $this->ensureUserBelongsToWorkspace($workspace);

            return response()->json([
                'success' => true,
                'data' => $workspace
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function update(WorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        try {

            $this->ensureOwner($workspace);

            $workspace = $this->workspaceService->update($workspace, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Workspace updated successfully',
                'data' => $workspace
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function destroy(Workspace $workspace): JsonResponse
    {
        try {

            $this->ensureOwner($workspace);

            $this->workspaceService->delete($workspace);

            return response()->json([
                'success' => true,
                'message' => 'Workspace deleted successfully'
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function addUser(Request $request, int $workspaceId)
    {
        try {

            $request->validate([
                'user_id' => 'required|exists:users,id',
                'role' => 'nullable|in:viewer,editor'
            ]);

            $this->ensureOwnerByWorkspaceId($workspaceId);

            $this->workspaceService->addUser(
                $workspaceId,
                $request->user_id,
                $request->role ?? 'viewer'
            );

            return response()->json([
                'success' => true,
                'message' => 'User added successfully'
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function removeUser(int $workspaceId, int $userId)
    {
        try {

            $this->ensureOwnerByWorkspaceId($workspaceId);

            $this->workspaceService->removeUser($workspaceId, $userId);

            return response()->json([
                'success' => true,
                'message' => 'User removed successfully'
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateRole(Request $request, int $workspaceId, int $userId)
    {
        try {

            $request->validate([
                'role' => 'required|in:viewer,editor'
            ]);

            $this->ensureOwnerByWorkspaceId($workspaceId);

            $this->workspaceService->updateUserRole(
                $workspaceId,
                $userId,
                $request->role
            );

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully'
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function members(int $workspaceId)
    {
        try {

            $this->ensureOwnerByWorkspaceId($workspaceId);

            $members = $this->workspaceService->getMembers($workspaceId);

            return response()->json([
                'success' => true,
                'data' => $members
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function ensureUserBelongsToWorkspace(Workspace $workspace): void
    {
        $exists = Auth::user()
            ->workspaces()
            ->where('workspace_id', $workspace->id)
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

    private function ensureOwnerByWorkspaceId(int $workspaceId): void
    {
        $workspace = Auth::user()
            ->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->first();

        $role = $workspace?->pivot?->role;

         if (!in_array($role, ['owner', 'editor'])) {
            throw new \Exception('Only owner and editor can perform this action');
        }
    }


    
}
