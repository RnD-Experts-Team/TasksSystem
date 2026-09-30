<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Admin CRUD for the tags of a board. */
class TagService
{
    public function __construct(private SettingsService $settings) {}

    /** @return Collection<int, Tag> */
    public function list(Board $board): Collection
    {
        return Tag::query()->where('board_id', $board->id)->withCount('posts')->orderBy('sort_order')->orderBy('id')->get();
    }

    public function create(Board $board, array $data): Tag
    {
        $tag = Tag::create([
            'board_id' => $board->id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($board->id, $data['slug'] ?? null, $data['name']),
            'color' => strtolower($data['color'] ?? '#64748b'),
            'sort_order' => ((int) Tag::query()->where('board_id', $board->id)->max('sort_order')) + 1,
        ]);
        $this->settings->forgetAll();

        return $tag->loadCount('posts');
    }

    public function update(Tag $tag, array $data): Tag
    {
        if (array_key_exists('name', $data)) {
            $tag->name = $data['name'];
        }
        if (! empty($data['slug']) && $data['slug'] !== $tag->slug) {
            $tag->slug = $this->uniqueSlug($tag->board_id, $data['slug'], $tag->name, $tag->id);
        }
        if (array_key_exists('color', $data) && $data['color']) {
            $tag->color = strtolower($data['color']);
        }
        $tag->save();
        $this->settings->forgetAll();

        return $tag->loadCount('posts');
    }

    public function delete(Tag $tag): void
    {
        $tag->delete(); // post_tag rows cascade
        $this->settings->forgetAll();
    }

    /** @param  int[]  $ids */
    public function reorder(Board $board, array $ids): void
    {
        DB::transaction(function () use ($board, $ids) {
            foreach (array_values($ids) as $index => $id) {
                Tag::query()->where('board_id', $board->id)->whereKey($id)->update(['sort_order' => $index]);
            }
        });
    }

    private function uniqueSlug(int $boardId, ?string $requested, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($requested ?: $name);
        $base = substr($base !== '' ? $base : 'tag', 0, 34);

        $slug = $base;
        $n = 2;
        while (Tag::query()->where('board_id', $boardId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
