<?php

namespace App\Services\Roadmap;

use App\Exceptions\RoadmapException;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Status;
use App\Models\Roadmap\StatusChange;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Admin CRUD for the statuses of a board. Exactly one default status per board. */
class StatusService
{
    public function __construct(private SettingsService $settings) {}

    /** @return Collection<int, Status> */
    public function list(Board $board): Collection
    {
        return Status::query()->where('board_id', $board->id)->withCount('posts')->orderBy('sort_order')->orderBy('id')->get();
    }

    public function create(Board $board, array $data): Status
    {
        return DB::transaction(function () use ($board, $data) {
            $status = Status::create([
                'board_id' => $board->id,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($board->id, $data['slug'] ?? null, $data['name']),
                'color' => strtolower($data['color']),
                'kind' => $data['kind'],
                'sort_order' => ((int) Status::query()->where('board_id', $board->id)->max('sort_order')) + 1,
                'is_roadmap_column' => (bool) ($data['is_roadmap_column'] ?? false),
                'is_default' => false,
                'locks_voting' => (bool) ($data['locks_voting'] ?? false),
            ]);

            $this->settings->forgetAll();

            return $status->loadCount('posts');
        });
    }

    public function update(Status $status, array $data): Status
    {
        if (array_key_exists('slug', $data) && $data['slug'] && $data['slug'] !== $status->slug) {
            $status->slug = $this->uniqueSlug($status->board_id, $data['slug'], $status->name, $status->id);
        }

        foreach (['name', 'kind', 'is_roadmap_column', 'locks_voting'] as $key) {
            if (array_key_exists($key, $data)) {
                $status->{$key} = $data[$key];
            }
        }
        if (array_key_exists('color', $data)) {
            $status->color = strtolower($data['color']);
        }

        $status->save();
        $this->settings->forgetAll();

        return $status->loadCount('posts');
    }

    /** Flip the default flag so that exactly one status of the board is the default. */
    public function makeDefault(Status $status): Status
    {
        DB::transaction(function () use ($status) {
            Status::query()->where('board_id', $status->board_id)->where('id', '!=', $status->id)->update(['is_default' => false]);
            $status->is_default = true;
            $status->save();
        });
        $this->settings->forgetAll();

        return $status->loadCount('posts');
    }

    /** Delete a status. Posts using it must be moved to $reassignTo (same board). */
    public function delete(Status $status, ?int $reassignTo): void
    {
        if ($status->is_default) {
            throw new RoadmapException('The default status cannot be deleted. Make another status the default first.', 'default_status');
        }

        $target = null;
        if ($reassignTo !== null) {
            $target = Status::query()->where('board_id', $status->board_id)->find($reassignTo);
            if (! $target || $target->id === $status->id) {
                throw new RoadmapException('Choose another status of this board to move the posts to.', 'invalid_reassign');
            }
        }

        $inUse = Post::query()->where('status_id', $status->id)->exists();
        if ($inUse && ! $target) {
            throw new RoadmapException('This status has posts. Choose a status to move them to.', 'reassign_required');
        }

        DB::transaction(function () use ($status, $target) {
            if ($target) {
                Post::query()->where('status_id', $status->id)->update(['status_id' => $target->id]);
                StatusChange::query()->where('to_status_id', $status->id)->update(['to_status_id' => $target->id]);
                StatusChange::query()->where('from_status_id', $status->id)->update(['from_status_id' => $target->id]);
            } else {
                // No posts use it any more; drop dangling history entries.
                StatusChange::query()->where('to_status_id', $status->id)->delete();
                StatusChange::query()->where('from_status_id', $status->id)->update(['from_status_id' => null]);
            }

            $status->delete();
        });

        $this->settings->forgetAll();
    }

    /** @param  int[]  $ids */
    public function reorder(Board $board, array $ids): void
    {
        DB::transaction(function () use ($board, $ids) {
            foreach (array_values($ids) as $index => $id) {
                Status::query()->where('board_id', $board->id)->whereKey($id)->update(['sort_order' => $index]);
            }
        });
        $this->settings->forgetAll();
    }

    private function uniqueSlug(int $boardId, ?string $requested, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($requested ?: $name);
        $base = substr($base !== '' ? $base : 'status', 0, 54);

        $slug = $base;
        $n = 2;
        while (Status::query()->where('board_id', $boardId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
