<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

class WorkspaceService
{
   
    public function getAll(): Collection
    {
        return Auth::user()
            ->workspaces()
            ->latest()
            ->get();
    }

    public function create(array $data): Workspace
    {
        return DB::transaction(function () use ($data) {

            $workspace = Workspace::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            Auth::user()->workspaces()->attach($workspace->id, [
                'role' => 'owner',
            ]);

            return $workspace;
        });
    }

    public function update(Workspace $workspace, array $data): Workspace
    {
        $workspace->update($data);
        return $workspace;
    }

    public function delete(Workspace $workspace): void
    {
        $workspace->delete();
    }

    public function addUser(int $workspaceId, int $userId, string $role = 'viewer'): void
    {
        try {
            DB::transaction(function () use ($workspaceId, $userId, $role) {

                $workspace = Workspace::findOrFail($workspaceId);
                $user = User::findOrFail($userId);

                $workspace->users()->syncWithoutDetaching([
                    $user->id => ['role' => $role]
                ]);
            });

        } catch (\Throwable $e) {
            throw new \Exception('Failed to add user');
        }
    }

    public function removeUser(int $workspaceId, int $userId): void
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);

            $workspace->users()->detach($userId);

        } catch (\Throwable $e) {
            throw new \Exception('Failed to remove user');
        }
    }

    public function updateUserRole(int $workspaceId, int $userId, string $role): void
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);

            $workspace->users()->updateExistingPivot($userId, [
                'role' => $role
            ]);

        } catch (\Throwable $e) {
            throw new \Exception('Failed to update role');
        }
    }

    public function getMembers(int $workspaceId)
    {
        try {
            $workspace = Workspace::with('users')->findOrFail($workspaceId);

            return $workspace->users;

        } catch (\Throwable $e) {
            throw new \Exception('Failed to fetch members');
        }
    }
    
}
