<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\AbuseEvent;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use App\Models\Roadmap\Vote;
use App\Services\Roadmap\PostCounters;
use App\Services\Roadmap\SuspicionService;
use Illuminate\Support\Facades\Cache;

class AbuseBulkTest extends RoadmapTestCase
{
    use AdminApiTrait;

    private const IP_X = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const IP_Y = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * Two visitors behind IP X (V1, V2) and one behind IP Y (V3), all voting on three posts.
     *
     * @return array{board:Board, posts:Post[], v1:Visitor, v2:Visitor, v3:Visitor}
     */
    private function scenario(): array
    {
        $board = $this->makeBoard();
        $posts = [
            $this->approvedPost($board, ['title' => 'One']),
            $this->approvedPost($board, ['title' => 'Two']),
            $this->approvedPost($board, ['title' => 'Three']),
        ];

        $v1 = $this->visitor(['first_ip_hash' => self::IP_X, 'last_ip_hash' => self::IP_X]);
        $v2 = $this->visitor(['first_ip_hash' => self::IP_X, 'last_ip_hash' => self::IP_X]);
        $v3 = $this->visitor(['first_ip_hash' => self::IP_Y, 'last_ip_hash' => self::IP_Y]);

        foreach ($posts as $post) {
            $this->vote($post->id, $v1->id, self::IP_X);
            $this->vote($post->id, $v3->id, self::IP_Y);
        }
        $this->vote($posts[0]->id, $v2->id, self::IP_X);
        $this->vote($posts[1]->id, $v2->id, self::IP_X);

        // Content: V1 wrote a post and a comment; V2 a comment; V3 a comment.
        $spamPost = $this->approvedPost($board, ['title' => 'Buy stuff', 'visitor_id' => $v1->id, 'ip_hash' => self::IP_X]);
        $this->comment($posts[0]->id, $v1->id, ['ip_hash' => self::IP_X, 'body' => 'spam 1']);
        $this->comment($posts[0]->id, $v2->id, ['ip_hash' => self::IP_X, 'body' => 'spam 2']);
        $this->comment($posts[0]->id, $v3->id, ['ip_hash' => self::IP_Y, 'body' => 'legit']);

        app(PostCounters::class)->recountPosts();
        app(PostCounters::class)->recountVisitors();

        return ['board' => $board, 'posts' => $posts + [3 => $spamPost], 'v1' => $v1->fresh(), 'v2' => $v2->fresh(), 'v3' => $v3->fresh()];
    }

    public function test_bulk_removal_by_visitor_removes_everything_recounts_and_bans(): void
    {
        $admin = $this->asAdmin();
        ['posts' => $posts, 'v1' => $v1, 'v2' => $v2, 'v3' => $v3] = $this->scenario();
        $this->assertSame(3, $posts[0]->fresh()->votes_count);
        $this->assertSame(3, $posts[0]->fresh()->comments_count);

        $this->postJson(self::ADMIN_API.'/abuse/bulk-remove', [
            'by' => 'visitor', 'value' => $v1->id, 'remove' => ['votes', 'posts', 'comments'], 'ban' => true, 'reason' => 'Spam run',
        ])->assertOk()->assertExactJson([
            'success' => true,
            'data' => ['votes_removed' => 3, 'posts_removed' => 1, 'comments_removed' => 1, 'visitors_banned' => 1],
            'message' => 'Content removed successfully',
        ]);

        // Votes gone, counters recomputed from the rows.
        $this->assertSame(0, Vote::where('visitor_id', $v1->id)->count());
        $this->assertSame(2, $posts[0]->fresh()->votes_count);  // v2, v3
        $this->assertSame(1, $posts[2]->fresh()->votes_count);  // v3
        // Content soft-removed (spam), no longer counted.
        $this->assertSame('spam', $posts[3]->fresh()->moderation_state);
        $this->assertSame('spam', Comment::where('body', 'spam 1')->first()->moderation_state);
        $this->assertSame(2, $posts[0]->fresh()->comments_count, 'approved comments only: v2 + v3');
        $this->assertSame(0, Post::query()->publiclyVisible()->whereKey($posts[3]->id)->count());

        // Ban + counters of the visitor.
        $v1->refresh();
        $this->assertTrue($v1->is_banned);
        $this->assertSame('Spam run', $v1->banned_reason);
        $this->assertSame($admin->id, $v1->banned_by);
        $this->assertSame(0, $v1->votes_count);
        $this->assertSame(0, $v1->approved_posts_count);
        $this->assertSame(0, $v1->approved_comments_count);

        // Everyone else untouched.
        $this->assertFalse($v2->fresh()->is_banned);
        $this->assertFalse($v3->fresh()->is_banned);
        $this->assertSame(2, Vote::where('visitor_id', $v2->id)->count());
        $this->assertSame(3, Vote::where('visitor_id', $v3->id)->count());
        $this->assertSame('approved', Comment::where('body', 'legit')->first()->moderation_state);
    }

    public function test_bulk_removal_by_ip_hash_removes_every_visitor_behind_it_and_bans_them_all(): void
    {
        $this->asAdmin();
        ['posts' => $posts, 'v1' => $v1, 'v2' => $v2, 'v3' => $v3] = $this->scenario();

        $this->postJson(self::ADMIN_API.'/abuse/bulk-remove', [
            'by' => 'ip_hash', 'value' => self::IP_X, 'remove' => ['votes', 'comments'], 'ban' => true,
        ])->assertOk()->assertJsonPath('data', [
            'votes_removed' => 5, 'posts_removed' => 0, 'comments_removed' => 2, 'visitors_banned' => 2,
        ]);

        $this->assertSame(0, Vote::where('ip_hash', self::IP_X)->count());
        $this->assertSame(3, Vote::where('ip_hash', self::IP_Y)->count());
        $this->assertSame([1, 1, 1], array_map(fn ($p) => $p->fresh()->votes_count, [$posts[0], $posts[1], $posts[2]]));
        $this->assertSame(1, $posts[0]->fresh()->comments_count);
        $this->assertSame('approved', $posts[3]->fresh()->moderation_state, 'posts were not requested');

        $this->assertTrue($v1->fresh()->is_banned);
        $this->assertTrue($v2->fresh()->is_banned);
        $this->assertFalse($v3->fresh()->is_banned);
        $this->assertSame(0, $v2->fresh()->votes_count);
        $this->assertSame(3, $v3->fresh()->votes_count);
    }

    public function test_removal_without_ban_and_partial_removal_leave_the_rest_alone(): void
    {
        $this->asAdmin();
        ['posts' => $posts, 'v1' => $v1] = $this->scenario();

        $this->postJson(self::ADMIN_API.'/abuse/bulk-remove', ['by' => 'visitor', 'value' => $v1->id, 'remove' => ['votes']])
            ->assertOk()->assertJsonPath('data', ['votes_removed' => 3, 'posts_removed' => 0, 'comments_removed' => 0, 'visitors_banned' => 0]);

        $this->assertFalse($v1->fresh()->is_banned);
        $this->assertSame('approved', $posts[3]->fresh()->moderation_state);
        $this->assertSame(3, $posts[0]->fresh()->comments_count);

        // Running it again is a harmless no-op.
        $this->postJson(self::ADMIN_API.'/abuse/bulk-remove', ['by' => 'visitor', 'value' => $v1->id, 'remove' => ['votes']])
            ->assertOk()->assertJsonPath('data.votes_removed', 0);
    }

    public function test_bulk_removal_validation(): void
    {
        $this->asAdmin();
        $post = fn (array $b) => $this->postJson(self::ADMIN_API.'/abuse/bulk-remove', $b);

        $post([])->assertStatus(422);
        $post(['by' => 'email', 'value' => 'x', 'remove' => ['votes']])->assertStatus(422)->assertJsonValidationErrors('by');
        $post(['by' => 'visitor', 'value' => 'x', 'remove' => []])->assertStatus(422)->assertJsonValidationErrors('remove');
        $post(['by' => 'visitor', 'value' => 'x', 'remove' => ['users']])->assertStatus(422);
        $post(['by' => 'visitor', 'value' => "x' OR 1=1 --", 'remove' => ['votes']])->assertStatus(422)->assertJsonValidationErrors('value');
        $post(['by' => 'visitor', 'value' => str_repeat('a', 65), 'remove' => ['votes']])->assertStatus(422);
    }

    public function test_banning_a_visitor_can_remove_their_content_and_votes(): void
    {
        $this->asAdmin();
        ['posts' => $posts, 'v1' => $v1] = $this->scenario();

        $res = $this->postJson(self::ADMIN_API."/visitors/{$v1->id}/ban", ['reason' => 'Abuse', 'remove_content' => true, 'remove_votes' => true])
            ->assertOk()
            ->assertJsonPath('data.is_banned', true)
            ->assertJsonPath('data.removed', ['votes_removed' => 3, 'posts_removed' => 1, 'comments_removed' => 1]);

        $this->assertSame(0, Vote::where('visitor_id', $v1->id)->count());
        $this->assertSame('spam', $posts[3]->fresh()->moderation_state);
        $this->assertSame(2, $posts[0]->fresh()->votes_count);
        $this->assertSame(0, $res->json('data.votes_count'));
    }

    public function test_abuse_events_list_filters_and_hides_the_full_hash(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        AbuseEvent::create(['type' => 'honeypot', 'ip_hash' => self::IP_X, 'board_id' => $board->id, 'meta' => ['field' => 'website'], 'created_at' => now()]);
        AbuseEvent::create(['type' => 'rate_limited', 'ip_hash' => self::IP_Y, 'created_at' => now()->subMinute()]);

        $all = $this->getJson(self::ADMIN_API.'/abuse/events')->assertOk()->assertJsonPath('pagination.total', 2);
        $this->assertSame('honeypot', $all->json('data.0.type'), 'newest first');
        $this->assertSame('aaaaaaaa', $all->json('data.0.ip_hash_short'));
        $this->assertSame(['field' => 'website'], $all->json('data.0.meta'));
        $this->assertStringNotContainsString(self::IP_X, $all->getContent());

        $this->getJson(self::ADMIN_API.'/abuse/events?type=rate_limited')->assertJsonPath('pagination.total', 1);
        $this->getJson(self::ADMIN_API.'/abuse/events?ip_hash=aaaa')->assertJsonPath('pagination.total', 1);
        $this->getJson(self::ADMIN_API.'/abuse/events?type=bogus')->assertStatus(422);
    }

    // ─── Suspicion heuristics ────────────────────────────────────────

    /** @return array<int, array<string,mixed>> */
    private function suspicious(?int $boardId = null): array
    {
        Cache::flush();
        $url = self::ADMIN_API.'/analytics/summary'.($boardId ? "?board_id=$boardId" : '');

        return $this->getJson($url)->assertOk()->json('data.suspicious');
    }

    private function reasons(array $items): array
    {
        return array_map(fn ($i) => $i['type'].':'.$i['reason_code'], $items);
    }

    public function test_quiet_activity_is_not_suspicious(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        foreach (range(1, 6) as $i) {
            $this->vote($post->id, $this->visitor(['first_ip_hash' => md5("q$i")])->id, md5("q$i"));
        }

        $this->assertSame([], $this->suspicious());
    }

    public function test_vote_spike_on_a_post_is_flagged(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board, ['title' => 'Spiky idea']);
        foreach (range(1, 12) as $i) {
            $v = $this->visitor(['first_ip_hash' => md5("s$i"), 'last_ip_hash' => md5("s$i")]);
            $this->vote($post->id, $v->id, md5("s$i"), now()->subMinutes($i));
        }
        Post::whereKey($post->id)->update(['votes_count' => 12]);

        $items = $this->suspicious($board->id);

        $spike = collect($items)->firstWhere('reason_code', 'vote_spike');
        $this->assertNotNull($spike, json_encode($items));
        $this->assertSame('post', $spike['type']);
        $this->assertSame((string) $post->id, $spike['ref']);
        $this->assertSame('Spiky idea', $spike['subject']);
        $this->assertSame(12, $spike['score']);
        $this->assertSame(['type', 'reason_code', 'subject', 'ref', 'score'], array_keys($spike));
    }

    public function test_votes_concentrated_on_a_few_ips_are_flagged(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        // 14 old votes (no spike) from 14 different visitors but only 2 ip hashes.
        foreach (range(1, 14) as $i) {
            $ip = $i % 2 === 0 ? self::IP_X : self::IP_Y;
            $v = $this->visitor(['first_ip_hash' => $ip, 'last_ip_hash' => $ip, 'first_seen_at' => now()->subDays(20)]);
            $this->vote($post->id, $v->id, $ip, now()->subDays(2)->addMinutes($i));
        }

        $items = $this->suspicious();

        $this->assertContains('post:concentrated_ips', $this->reasons($items));
        $this->assertNotContains('post:vote_spike', $this->reasons($items));
    }

    public function test_one_ip_minting_many_visitors_is_flagged_and_marks_those_visitors(): void
    {
        $this->asAdmin();
        foreach (range(1, 6) as $i) {
            $this->visitor(['first_ip_hash' => self::IP_X, 'last_ip_hash' => self::IP_X, 'first_seen_at' => now()->subHours($i)]);
        }
        $calm = $this->visitor(['first_ip_hash' => self::IP_Y, 'last_ip_hash' => self::IP_Y]);
        // Old visitors from the same IP do not count.
        foreach (range(1, 6) as $i) {
            $this->visitor(['first_ip_hash' => self::IP_Y, 'last_ip_hash' => self::IP_Y, 'first_seen_at' => now()->subDays(10)]);
        }

        $items = $this->suspicious();

        $mint = collect($items)->firstWhere('reason_code', 'ip_mints_visitors');
        $this->assertSame('ip_hash', $mint['type']);
        $this->assertSame(self::IP_X, $mint['ref'], 'the full hash is usable with bulk-remove');
        $this->assertSame('aaaaaaaa', $mint['subject']);
        $this->assertSame(6, $mint['score']);
        $this->assertCount(1, array_filter($items, fn ($i) => $i['reason_code'] === 'ip_mints_visitors'));

        // The visitor list carries the flag.
        $rows = collect($this->getJson(self::ADMIN_API.'/visitors?per_page=100')->json('data'));
        $this->assertSame(6, $rows->where('suspicious', true)->count());
        $this->assertFalse($rows->firstWhere('id', $calm->id)['suspicious']);
    }

    public function test_a_visitor_voting_in_bursts_is_flagged(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $bot = $this->visitor();
        $human = $this->visitor();
        foreach (range(1, 22) as $i) {
            $post = $this->approvedPost($board);
            $this->vote($post->id, $bot->id, self::IP_X, now()->subMinutes(5)->addSeconds($i * 5));
        }
        // A human voting 22 times over two hours is fine.
        foreach (range(1, 22) as $i) {
            $this->vote($this->approvedPost($board)->id, $human->id, self::IP_Y, now()->subMinutes(200)->addMinutes($i * 5));
        }

        $items = $this->suspicious();

        $burst = collect($items)->where('reason_code', 'vote_burst');
        $this->assertCount(1, $burst);
        $this->assertSame($bot->id, $burst->first()['ref']);
        $this->assertSame('visitor', $burst->first()['type']);
        $this->assertGreaterThanOrEqual(20, $burst->first()['score']);

        $row = collect($this->getJson(self::ADMIN_API.'/visitors?per_page=100')->json('data'));
        $this->assertTrue($row->firstWhere('id', $bot->id)['suspicious']);
        $this->assertFalse($row->firstWhere('id', $human->id)['suspicious']);
    }

    public function test_a_cluster_of_brand_new_visitors_on_one_post_is_flagged(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board, ['title' => 'Brigaded']);
        // 10 votes 2-11 hours ago (so no hourly spike) from visitors first seen 12 hours ago.
        foreach (range(1, 10) as $i) {
            $v = $this->visitor(['first_ip_hash' => md5("n$i"), 'last_ip_hash' => md5("n$i"), 'first_seen_at' => now()->subHours(12)]);
            $this->vote($post->id, $v->id, md5("n$i"), now()->subHours($i + 1));
        }

        $items = $this->suspicious();

        $cluster = collect($items)->firstWhere('reason_code', 'new_visitor_cluster');
        $this->assertNotNull($cluster, json_encode($items));
        $this->assertSame('Brigaded', $cluster['subject']);
        $this->assertSame(10, $cluster['score']);
        $this->assertNotContains('post:vote_spike', $this->reasons($items));
    }

    public function test_suspicion_results_are_cached_for_a_minute(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        foreach (range(1, 12) as $i) {
            $this->vote($post->id, $this->visitor()->id, md5("c$i"), now()->subMinutes($i));
        }

        $service = app(SuspicionService::class);
        $first = $service->items(null, true);
        $this->assertNotEmpty($first);

        Vote::query()->delete();
        $this->assertSame($first, $service->items(null), 'served from cache');
        $this->assertSame([], $service->items(null, true), 'fresh recompute sees the deletion');
    }
}
