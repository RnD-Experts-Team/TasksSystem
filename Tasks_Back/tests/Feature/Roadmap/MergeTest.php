<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Tag;
use App\Models\Roadmap\Visitor;
use App\Models\Roadmap\Vote;
use App\Services\Roadmap\PostCounters;
use Laravel\Sanctum\Sanctum;

class MergeTest extends RoadmapTestCase
{
    use AdminApiTrait;

    /** @return array{0: Post, 1: Post, 2: Visitor[]} source, target, visitors v1..v7 */
    private function scenario(): array
    {
        $board = $this->makeBoard(['slug' => 'web']);
        $source = $this->approvedPost($board, ['title' => 'Dark theme']);
        $target = $this->approvedPost($board, ['title' => 'Dark mode']);

        $v = [];
        foreach (range(1, 7) as $i) {
            $v[$i] = $this->visitor();
        }

        // source: v1..v5, target: v4..v7  =>  overlap v4, v5
        foreach ([1, 2, 3, 4, 5] as $i) {
            $this->vote($source->id, $v[$i]->id, str_repeat((string) $i, 32));
        }
        foreach ([4, 5, 6, 7] as $i) {
            $this->vote($target->id, $v[$i]->id, str_repeat((string) $i, 32));
        }
        Post::whereKey($source->id)->update(['votes_count' => 5]);
        Post::whereKey($target->id)->update(['votes_count' => 4]);
        app(PostCounters::class)->recountVisitors();

        return [$source->fresh(), $target->fresh(), $v];
    }

    public function test_preview_reports_what_would_move(): void
    {
        $this->asAdmin();
        [$source, $target] = $this->scenario();
        $this->comment($source->id, null, ['moderation_state' => 'approved']);
        $this->comment($source->id, null, ['moderation_state' => 'pending']);

        $this->postJson(self::ADMIN_API."/posts/{$source->id}/merge/preview", ['target_post_id' => $target->id])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => ['votes_moving' => 3, 'overlapping_voters' => 2, 'comments_moving' => 2, 'target_votes_after' => 7],
                'message' => 'Merge preview generated successfully',
            ]);

        // Preview changes nothing.
        $this->assertSame(5, Vote::where('post_id', $source->id)->count());
        $this->assertNull($source->fresh()->merged_into_post_id);
    }

    public function test_merge_moves_votes_without_double_counting_overlapping_visitors(): void
    {
        $this->asAdmin();
        [$source, $target, $v] = $this->scenario();

        $this->postJson(self::ADMIN_API."/posts/{$source->id}/merge", ['target_post_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.votes_count', 7);

        // 5 + 4 - 2 overlapping = 7 distinct voters on the target, none left on the source.
        $this->assertSame(7, Vote::where('post_id', $target->id)->count());
        $this->assertSame(7, Vote::where('post_id', $target->id)->distinct('visitor_id')->count('visitor_id'));
        $this->assertSame(0, Vote::where('post_id', $source->id)->count());
        $this->assertSame(7, $target->fresh()->votes_count);
        $this->assertSame(0, $source->fresh()->votes_count);

        // Every visitor's own counter matches the rows after the merge (v4, v5 lost their duplicate vote).
        foreach ($v as $visitor) {
            $this->assertSame(Vote::where('visitor_id', $visitor->id)->count(), $visitor->fresh()->votes_count);
            $this->assertSame(1, $visitor->fresh()->votes_count);
        }
    }

    public function test_merge_moves_comments_and_remembers_their_origin(): void
    {
        $this->asAdmin();
        [$source, $target] = $this->scenario();
        $top = $this->comment($source->id, null, ['body' => 'top level']);
        $reply = $this->comment($source->id, null, ['body' => 'a reply', 'parent_id' => $top->id]);
        $pending = $this->comment($source->id, null, ['body' => 'pending one', 'moderation_state' => 'pending']);
        $own = $this->comment($target->id, null, ['body' => 'already here']);
        Post::whereKey($source->id)->update(['comments_count' => 2]);
        Post::whereKey($target->id)->update(['comments_count' => 1]);

        $this->postJson(self::ADMIN_API."/posts/{$source->id}/merge", ['target_post_id' => $target->id])->assertOk();

        foreach ([$top, $reply, $pending] as $moved) {
            $fresh = $moved->fresh();
            $this->assertSame($target->id, $fresh->post_id);
            $this->assertSame($source->id, $fresh->original_post_id);
        }
        $this->assertSame($top->id, $reply->fresh()->parent_id, 'thread structure is kept');
        $this->assertNull($own->fresh()->original_post_id, 'comments already on the target are untouched');
        $this->assertSame(3, $target->fresh()->comments_count, 'approved comments only: own + top + reply');
        $this->assertSame(0, $source->fresh()->comments_count);
        $this->assertSame(0, Comment::where('post_id', $source->id)->count());
    }

    public function test_merge_unions_tags_hides_the_source_and_writes_a_redirect(): void
    {
        $this->asAdmin();
        [$source, $target] = $this->scenario();
        $a = Tag::create(['board_id' => $source->board_id, 'name' => 'A', 'slug' => 'a']);
        $b = Tag::create(['board_id' => $source->board_id, 'name' => 'B', 'slug' => 'b']);
        $c = Tag::create(['board_id' => $source->board_id, 'name' => 'C', 'slug' => 'c']);
        $source->tags()->attach([$a->id, $b->id]);
        $target->tags()->attach([$b->id, $c->id]);
        Post::whereKey($source->id)->update(['is_pinned' => true]);

        $res = $this->postJson(self::ADMIN_API."/posts/{$source->id}/merge", ['target_post_id' => $target->id])->assertOk();

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $target->tags()->pluck('roadmap_tags.id')->all());
        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], array_column($res->json('data.tags'), 'slug'));

        $source->refresh();
        $this->assertSame($target->id, $source->merged_into_post_id);
        $this->assertNotNull($source->merged_at);
        $this->assertFalse($source->is_pinned);
        $this->assertSame(0, Post::query()->publiclyVisible()->whereKey($source->id)->count(), 'source is hidden');
        $this->assertSame(1, Post::query()->publiclyVisible()->whereKey($target->id)->count());

        $this->assertDatabaseHas('roadmap_slug_redirects', ['kind' => 'post', 'old_key' => $source->board_id.':'.$source->number, 'target_id' => $target->id]);

        // The merged post is gone from the public list and the target shows the merged votes.
        $list = $this->getJson('/api/public/roadmap/boards/web/posts')->assertOk();
        $numbers = array_column($list->json('data'), 'number');
        $this->assertNotContains($source->number, $numbers);
        $this->assertContains($target->number, $numbers);
        $this->assertSame(7, collect($list->json('data'))->firstWhere('number', $target->number)['votes_count']);

        // The detail shows the merged post's admin view with merged_from.
        $this->getJson(self::ADMIN_API."/posts/{$target->id}")->assertJsonPath('data.merged_from.0.id', $source->id);
        $this->getJson(self::ADMIN_API."/posts/{$source->id}")->assertJsonPath('data.merged_into_post_id', $target->id);
    }

    public function test_merge_rules(): void
    {
        $this->asAdmin();
        [$source, $target] = $this->scenario();
        $otherBoard = $this->makeBoard();
        $foreign = $this->approvedPost($otherBoard);
        $url = fn (Post $p, string $suffix = 'merge') => self::ADMIN_API."/posts/{$p->id}/$suffix";

        // Cross-board.
        $this->postJson($url($source), ['target_post_id' => $foreign->id])->assertStatus(400)->assertJsonPath('code', 'merge_cross_board');
        $this->postJson($url($source, 'merge/preview'), ['target_post_id' => $foreign->id])->assertStatus(400)->assertJsonPath('code', 'merge_cross_board');
        // Itself.
        $this->postJson($url($source), ['target_post_id' => $source->id])->assertStatus(400)->assertJsonPath('code', 'merge_self');
        // Unknown / missing target.
        $this->postJson($url($source), ['target_post_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('target_post_id');
        $this->postJson($url($source), [])->assertStatus(422);
        $this->postJson(self::ADMIN_API.'/posts/999999/merge', ['target_post_id' => $target->id])->assertNotFound();
        // Nothing changed.
        $this->assertSame(5, Vote::where('post_id', $source->id)->count());

        // Merge, then merging the same source again or into a merged target is refused.
        $this->postJson($url($source), ['target_post_id' => $target->id])->assertOk();
        $this->postJson($url($source), ['target_post_id' => $target->id])->assertStatus(400)->assertJsonPath('code', 'already_merged');
        $third = $this->approvedPost($source->board);
        $this->postJson($url($third), ['target_post_id' => $source->id])->assertStatus(400)->assertJsonPath('code', 'target_merged');
    }

    public function test_merging_a_post_that_absorbed_others_repoints_the_chain(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        [$a, $b, $c] = [$this->approvedPost($board), $this->approvedPost($board), $this->approvedPost($board)];
        $v1 = $this->visitor();
        $v2 = $this->visitor();
        $this->vote($a->id, $v1->id);
        $this->vote($b->id, $v2->id);

        $this->postJson(self::ADMIN_API."/posts/{$a->id}/merge", ['target_post_id' => $b->id])->assertOk();
        $this->postJson(self::ADMIN_API."/posts/{$b->id}/merge", ['target_post_id' => $c->id])->assertOk();

        $this->assertSame($c->id, $a->fresh()->merged_into_post_id, 'A now points at the final post');
        $this->assertSame($c->id, $b->fresh()->merged_into_post_id);
        $this->assertSame(2, $c->fresh()->votes_count);
        $this->assertSame(2, Vote::where('post_id', $c->id)->count());
    }

    public function test_merge_requires_write_permission_and_touches_no_public_leak(): void
    {
        [$source, $target] = $this->scenario();
        Sanctum::actingAs($this->staff('moderate roadmap'), ['*']);

        $this->postJson(self::ADMIN_API."/posts/{$source->id}/merge", ['target_post_id' => $target->id])->assertForbidden();
        $this->assertNull($source->fresh()->merged_into_post_id);

        Sanctum::actingAs($this->staff('manage roadmap'), ['*']);
        $this->postJson(self::ADMIN_API."/posts/{$source->id}/merge", ['target_post_id' => $target->id])->assertOk();
    }
}
