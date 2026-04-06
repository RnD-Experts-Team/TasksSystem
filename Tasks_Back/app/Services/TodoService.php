<?php

namespace App\Services;

use App\Models\Todo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TodoService
{
    public function getAllByWorkspace(int $workspaceId)
    {
        return Todo::where('workspace_id', $workspaceId)
            ->whereNull('parent_id') // only todo main
            ->with('subtodos')
            ->latest()
            ->get();
    }

    public function create(array $data): Todo
    {
        return DB::transaction(function () use ($data) {

            return Todo::create([
                'workspace_id' => $data['workspace_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'title' => $data['title'],
                'status' => $data['status'] ?? 'pending',
                'due_date' => $data['due_date'] ?? null,
            ]);
        });
    }

    public function update(Todo $todo, array $data): Todo
    {
        $todo->update($data);
        return $todo;
    }

    public function delete(Todo $todo): void
    {
        $todo->delete();
    }
}
