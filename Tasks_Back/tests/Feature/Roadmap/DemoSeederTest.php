<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Vote;
use Database\Seeders\RoadmapDemoSeeder;

class DemoSeederTest extends RoadmapTestCase
{
    public function test_demo_seeder_builds_a_consistent_board_and_is_idempotent(): void
    {
        $this->seed(RoadmapDemoSeeder::class);
        $this->seed(RoadmapDemoSeeder::class);

        $this->assertSame(1, Board::count());
        $board = Board::firstOrFail();
        $this->assertSame('tasks-system', $board->slug);

        $this->assertSame(12, Post::where('moderation_state', 'approved')->count());
        $this->assertSame(2, Post::where('moderation_state', 'pending')->count());
        $this->assertSame(2, ChangelogEntry::count());
        $this->assertSame(3, $board->tags()->count());
        $this->assertSame(5, $board->statuses()->count());

        // denormalised counters match the rows
        foreach (Post::all() as $post) {
            $this->assertSame(Vote::where('post_id', $post->id)->count(), $post->votes_count);
        }
        $this->assertSame(Post::max('number') + 1, $board->fresh()->next_post_number);

        $list = $this->getJson(self::API.'/boards/tasks-system/posts?sort=trending')->assertOk();
        $this->assertSame(12, $list->json('pagination.total'));

        $roadmap = $this->getJson(self::API.'/boards/tasks-system/roadmap')->assertOk()->json('data.columns');
        $this->assertCount(3, $roadmap);
        $this->assertGreaterThan(0, collect($roadmap)->sum(fn ($c) => count($c['posts'])));

        $this->getJson(self::API.'/changelog')->assertOk()->assertJsonCount(2, 'data');
    }
}
