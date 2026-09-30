<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\Post;
use App\Models\Roadmap\Vote;

class VoteTest extends RoadmapTestCase
{
    private function vote(string $token, $board, $post, bool $voted = true)
    {
        return $this->withVisitor($token)->postJson(
            self::API.'/boards/'.$board->slug.'/posts/'.$post->number.'/vote',
            ['voted' => $voted]
        );
    }

    public function test_vote_is_idempotent_and_count_matches_rows(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        ['token' => $token] = $this->issueVisitor();

        $this->vote($token, $board, $post)->assertOk()->assertJson(['success' => true, 'data' => ['voted' => true, 'votes_count' => 1]]);
        $this->vote($token, $board, $post)->assertOk()->assertJsonPath('data.votes_count', 1);

        $this->assertSame(1, Vote::where('post_id', $post->id)->count());
        $this->assertSame(1, $post->fresh()->votes_count);

        $this->vote($token, $board, $post, false)->assertOk()->assertJson(['data' => ['voted' => false, 'votes_count' => 0]]);
        $this->vote($token, $board, $post, false)->assertOk()->assertJsonPath('data.votes_count', 0);

        $this->assertSame(0, Vote::where('post_id', $post->id)->count());
        $this->assertSame(0, $post->fresh()->votes_count);
    }

    public function test_votes_from_several_visitors_add_up_and_visitor_counters_follow(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        $a = $this->issueVisitor();
        $b = $this->issueVisitor();

        $this->vote($a['token'], $board, $post)->assertJsonPath('data.votes_count', 1);
        $this->vote($b['token'], $board, $post)->assertJsonPath('data.votes_count', 2);
        $this->vote($a['token'], $board, $post, false)->assertJsonPath('data.votes_count', 1);

        $this->assertSame(1, $post->fresh()->votes_count);
        $this->assertSame(0, $a['visitor']->fresh()->votes_count);
        $this->assertSame(1, $b['visitor']->fresh()->votes_count);
    }

    public function test_voting_closed_board_and_locking_status_return_400(): void
    {
        ['token' => $token] = $this->issueVisitor();

        $closed = $this->makeBoard(['allow_votes' => false]);
        $post = $this->approvedPost($closed);
        $this->vote($token, $closed, $post)->assertStatus(400)->assertJson(['success' => false, 'code' => 'voting_closed', 'data' => null]);

        $board = $this->makeBoard();
        $locked = $this->approvedPost($board, ['status_id' => $this->statusOf($board, 'not-planned')->id]);
        $this->vote($token, $board, $locked)->assertStatus(400)->assertJson(['code' => 'voting_closed']);

        $this->assertSame(0, Vote::count());
    }

    public function test_voting_on_a_non_public_post_is_404(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        foreach (['pending', 'rejected', 'spam'] as $state) {
            $post = $this->makePost($board, ['moderation_state' => $state]);
            $this->vote($token, $board, $post)->assertStatus(404)->assertJson(['success' => false, 'code' => 'not_found']);
        }

        $target = $this->approvedPost($board);
        $merged = $this->approvedPost($board, ['merged_into_post_id' => $target->id]);
        $this->vote($token, $board, $merged)->assertStatus(404);

        $this->vote($token, $board, (object) ['number' => 9999])->assertStatus(404);
    }

    public function test_missing_or_unknown_visitor_token_is_401(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        $url = self::API.'/boards/'.$board->slug.'/posts/'.$post->number.'/vote';

        $this->postJson($url, ['voted' => true])
            ->assertStatus(401)->assertJson(['success' => false, 'code' => 'visitor_token_required']);

        $this->withVisitor('rmv_doesnotexist')->postJson($url, ['voted' => true])
            ->assertStatus(401)->assertJson(['success' => false, 'code' => 'visitor_token_invalid']);
    }

    public function test_vote_body_is_validated(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        ['token' => $token] = $this->issueVisitor();

        $this->withVisitor($token)->postJson(self::API.'/boards/'.$board->slug.'/posts/'.$post->number.'/vote', [])
            ->assertStatus(422)->assertJsonValidationErrors('voted');
    }

    public function test_banned_visitor_gets_a_fake_success_and_nothing_is_stored(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        ['token' => $token, 'visitor' => $visitor] = $this->issueVisitor(['is_banned' => true]);

        $this->vote($token, $board, $post)->assertOk()->assertJson(['success' => true, 'data' => ['voted' => true, 'votes_count' => 0]]);

        $this->assertSame(0, Vote::count());
        $this->assertSame(0, $post->fresh()->votes_count);
        $this->assertDatabaseHas('roadmap_abuse_events', ['type' => 'banned_write', 'visitor_id' => $visitor->id]);
    }

    public function test_recount_command_repairs_drifted_counters(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board, ['votes_count' => 99]);
        $a = $this->issueVisitor();
        $b = $this->issueVisitor();
        Vote::create(['post_id' => $post->id, 'visitor_id' => $a['visitor']->id, 'created_at' => now()]);
        Vote::create(['post_id' => $post->id, 'visitor_id' => $b['visitor']->id, 'created_at' => now()]);

        $this->artisan('roadmap:recount')->assertSuccessful();

        $this->assertSame(2, Post::find($post->id)->votes_count);
        $this->assertSame(1, $a['visitor']->fresh()->votes_count);
    }

    public function test_vote_response_never_goes_negative(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        ['token' => $token] = $this->issueVisitor();

        $this->vote($token, $board, $post, false)->assertOk()->assertJsonPath('data.votes_count', 0);
        $this->assertSame(0, $post->fresh()->votes_count);
    }
}
