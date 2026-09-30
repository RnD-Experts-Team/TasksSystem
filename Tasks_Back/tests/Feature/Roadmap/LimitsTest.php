<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\AbuseEvent;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use Illuminate\Support\Carbon;

class LimitsTest extends RoadmapTestCase
{
    private function vote(string $token, $board, $post, bool $voted = true)
    {
        return $this->withVisitor($token)->postJson(
            self::API.'/boards/'.$board->slug.'/posts/'.$post->number.'/vote',
            ['voted' => $voted]
        );
    }

    private function assertLimitBody($response, string $code): void
    {
        $response->assertStatus(429)
            ->assertJson(['success' => false, 'data' => null, 'code' => $code])
            ->assertJsonStructure(['success', 'data', 'message', 'code', 'retry_after']);
        $this->assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
        $this->assertSame((int) $response->headers->get('Retry-After'), $response->json('retry_after'));
    }

    // ─── durable daily caps ──────────────────────────────────────

    public function test_votes_per_visitor_day_cap_returns_429_daily_limit(): void
    {
        $this->setSettings(['limits' => ['votes_per_visitor_day' => 3]]);
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        for ($i = 0; $i < 3; $i++) {
            $this->vote($token, $board, $this->approvedPost($board))->assertOk();
        }

        $this->assertLimitBody($this->vote($token, $board, $this->approvedPost($board)), 'daily_limit');

        // an existing vote is idempotent and does not need quota
        $first = Post::query()->orderBy('id')->first();
        $this->vote($token, $board, $first)->assertOk();
    }

    public function test_votes_per_ip_day_cap_applies_across_visitors(): void
    {
        $this->setSettings(['limits' => ['votes_per_ip_day' => 2]]);
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        $a = $this->issueVisitor();
        $b = $this->issueVisitor();
        $c = $this->issueVisitor();

        $this->vote($a['token'], $board, $post)->assertOk();
        $this->vote($b['token'], $board, $post)->assertOk();
        $this->assertLimitBody($this->vote($c['token'], $board, $post), 'daily_limit');
        $this->assertSame(2, $post->fresh()->votes_count);
    }

    public function test_new_visitors_have_a_lower_daily_vote_cap(): void
    {
        $this->setSettings(['limits' => ['new_visitor_votes_day' => 2, 'votes_per_visitor_day' => 30]]);
        $board = $this->makeBoard();
        $fresh = $this->issueVisitor(['first_seen_at' => now()]);
        $old = $this->issueVisitor(['first_seen_at' => now()->subDays(2)]);

        for ($i = 0; $i < 2; $i++) {
            $this->vote($fresh['token'], $board, $this->approvedPost($board))->assertOk();
        }
        $this->assertLimitBody($this->vote($fresh['token'], $board, $this->approvedPost($board)), 'daily_limit');

        for ($i = 0; $i < 4; $i++) {
            $this->vote($old['token'], $board, $this->approvedPost($board))->assertOk();
        }
    }

    public function test_daily_vote_window_rolls_after_24_hours(): void
    {
        $this->setSettings(['limits' => ['votes_per_visitor_day' => 1]]);
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor(['first_seen_at' => now()->subDays(5)]);

        $this->vote($token, $board, $this->approvedPost($board))->assertOk();
        $this->assertLimitBody($this->vote($token, $board, $this->approvedPost($board)), 'daily_limit');

        Carbon::setTestNow(now()->addHours(25));
        $this->vote($token, $board, $this->approvedPost($board))->assertOk();
    }

    public function test_posts_per_visitor_day_cap(): void
    {
        $this->setSettings(['limits' => ['posts_per_visitor_day' => 1], 'moderation' => ['min_post_seconds' => 0]]);
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $board)->assertCreated();
        $second = $this->submitPost($token, $board, ['title' => 'A completely different idea']);
        $this->assertLimitBody($second, 'daily_limit');
        $this->assertSame(1, Post::count());
    }

    public function test_posts_per_ip_day_cap(): void
    {
        $this->setSettings(['limits' => ['posts_per_ip_day' => 1], 'moderation' => ['min_post_seconds' => 0]]);
        $board = $this->makeBoard();
        $a = $this->issueVisitor();
        $b = $this->issueVisitor();

        $this->submitPost($a['token'], $board)->assertCreated();
        $this->assertLimitBody($this->submitPost($b['token'], $board, ['title' => 'Another idea from the same network']), 'daily_limit');
    }

    public function test_comments_per_visitor_hour_cap(): void
    {
        $this->setSettings(['limits' => ['comments_per_visitor_hour' => 1], 'moderation' => ['min_comment_seconds' => 0]]);
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        ['token' => $token] = $this->issueVisitor();

        $this->submitComment($token, $board, $post)->assertCreated();
        $this->assertLimitBody($this->submitComment($token, $board, $post, ['body' => 'a second and different comment']), 'daily_limit');
        $this->assertSame(1, Comment::count());

        Carbon::setTestNow(now()->addHours(2));
        $this->submitComment($token, $board, $post, ['body' => 'third comment an hour later'])->assertCreated();
    }

    public function test_tokens_per_ip_day_cap_on_visitor_issue(): void
    {
        $this->setSettings(['limits' => ['tokens_per_ip_day' => 2]]);

        $this->postJson(self::API.'/visitor')->assertStatus(201)->assertJsonPath('data.is_new', true);
        $this->postJson(self::API.'/visitor')->assertStatus(201);
        $this->assertLimitBody($this->postJson(self::API.'/visitor'), 'daily_limit');
        $this->assertSame(2, Visitor::count());
    }

    // ─── short-window throttles ──────────────────────────────────

    public function test_vote_throttle_is_20_per_minute_per_visitor(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        ['token' => $token] = $this->issueVisitor();

        for ($i = 0; $i < 20; $i++) {
            $this->vote($token, $board, $post)->assertOk();
        }

        $this->assertLimitBody($this->vote($token, $board, $post), 'rate_limited');

        // a different visitor (different bucket) still works
        ['token' => $other] = $this->issueVisitor();
        $this->vote($other, $board, $post)->assertOk();

        Carbon::setTestNow(now()->addSeconds(61));
        $this->vote($token, $board, $post)->assertOk();
    }

    public function test_vote_throttle_per_ip_is_60_per_minute(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);

        for ($i = 0; $i < 60; $i++) {
            ['token' => $token] = $this->issueVisitor();
            $this->vote($token, $board, $post)->assertOk();
        }

        ['token' => $token] = $this->issueVisitor();
        $this->assertLimitBody($this->vote($token, $board, $post), 'rate_limited');
    }

    public function test_visitor_issue_is_5_per_minute_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::API.'/visitor')->assertStatus(201);
        }

        $this->assertLimitBody($this->postJson(self::API.'/visitor'), 'rate_limited');
        $this->assertSame(5, Visitor::count());

        Carbon::setTestNow(now()->addSeconds(61));
        $this->postJson(self::API.'/visitor')->assertStatus(201);
    }

    public function test_post_submit_throttle_is_2_per_minute_per_visitor(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $tokens = [
            $this->startForm($token, $board), $this->startForm($token, $board), $this->startForm($token, $board),
        ];
        $this->passMinTime();

        $this->submitPost($token, $board, ['title' => 'First reasonable idea', 'form_token' => $tokens[0]], false)->assertCreated();
        $this->submitPost($token, $board, ['title' => 'Second reasonable idea', 'form_token' => $tokens[1]], false)->assertCreated();
        $this->assertLimitBody(
            $this->submitPost($token, $board, ['title' => 'Third reasonable idea', 'form_token' => $tokens[2]], false),
            'rate_limited'
        );
    }

    public function test_read_and_suggest_throttles(): void
    {
        $board = $this->makeBoard();

        for ($i = 0; $i < 30; $i++) {
            $this->getJson(self::API.'/boards/'.$board->slug.'/posts/similar?q=hello')->assertOk();
        }
        $this->assertLimitBody($this->getJson(self::API.'/boards/'.$board->slug.'/posts/similar?q=hello'), 'rate_limited');

        // reads have their own, much larger bucket
        $this->getJson(self::API.'/boards')->assertOk();
    }

    public function test_a_rejected_request_does_not_consume_quota_and_events_are_sampled(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        ['token' => $token, 'visitor' => $visitor] = $this->issueVisitor();

        for ($i = 0; $i < 20; $i++) {
            $this->vote($token, $board, $post)->assertOk();
        }
        for ($i = 0; $i < 10; $i++) {
            $this->vote($token, $board, $post)->assertStatus(429);
        }

        // sampled with Cache::add: one event in the 5-minute window, however many 429s
        $this->assertSame(1, AbuseEvent::where('type', 'rate_limited')->where('visitor_id', $visitor->id)->count());

        Carbon::setTestNow(now()->addMinutes(6));
        for ($i = 0; $i < 25; $i++) {
            $this->vote($token, $board, $post);
        }
        $this->assertSame(2, AbuseEvent::where('type', 'rate_limited')->where('visitor_id', $visitor->id)->count());
    }
}
