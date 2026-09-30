<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\AbuseEvent;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use App\Models\Roadmap\Vote;
use App\Support\Roadmap\ClientIp;
use App\Support\Roadmap\IpHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class VisitorTest extends RoadmapTestCase
{
    // ─── token flows ─────────────────────────────────────────────

    public function test_issue_creates_a_visitor_and_stores_only_the_hash(): void
    {
        $response = $this->postJson(self::API.'/visitor')->assertCreated()->assertJson(['success' => true, 'data' => ['is_new' => true]]);
        $token = $response->json('data.visitor_token');

        $this->assertStringStartsWith('rmv_', $token);
        $this->assertGreaterThanOrEqual(43, strlen($token));

        $visitor = Visitor::firstOrFail();
        $this->assertSame(hash('sha256', $token), $visitor->token_hash);
        $this->assertSame(26, strlen($visitor->id));
        $this->assertSame(32, strlen($visitor->first_ip_hash));
        $this->assertSame($visitor->first_ip_hash, $visitor->last_ip_hash);
        $this->assertNotNull($visitor->first_seen_at);
        $this->assertStringNotContainsString($token, json_encode(\DB::table('roadmap_visitors')->get()));
        $this->assertStringNotContainsString('127.0.0.1', json_encode(\DB::table('roadmap_visitors')->get()));
    }

    public function test_issue_reuses_a_valid_token_and_mints_a_new_one_for_an_unknown_token(): void
    {
        $first = $this->postJson(self::API.'/visitor')->json('data.visitor_token');

        $this->flushHeaders();
        $again = $this->withVisitor($first)->postJson(self::API.'/visitor')->assertOk();
        $this->assertSame($first, $again->json('data.visitor_token'));
        $this->assertFalse($again->json('data.is_new'));
        $this->assertSame(1, Visitor::count());

        $this->flushHeaders();
        $fresh = $this->withVisitor('rmv_definitelyunknown')->postJson(self::API.'/visitor')->assertCreated();
        $this->assertTrue($fresh->json('data.is_new'));
        $this->assertNotSame($first, $fresh->json('data.visitor_token'));
        $this->assertSame(2, Visitor::count());
    }

    public function test_issued_token_works_for_writes_end_to_end(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        $token = $this->postJson(self::API.'/visitor')->json('data.visitor_token');

        $this->flushHeaders();
        $this->withVisitor($token)->postJson(self::API.'/boards/'.$board->slug.'/posts/'.$post->number.'/vote', ['voted' => true])
            ->assertOk()->assertJsonPath('data.votes_count', 1);
    }

    public function test_optional_routes_ignore_a_bad_token_and_required_routes_reject_it(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);

        $this->withVisitor('rmv_garbage')->getJson(self::API.'/boards/'.$board->slug.'/posts/'.$post->number)->assertOk();
        $this->withVisitor('rmv_garbage')->getJson(self::API.'/me/state')->assertOk();
        $this->withVisitor(str_repeat('x', 500))->postJson(self::API.'/forms/post/start', ['board' => $board->slug])
            ->assertStatus(401)->assertJson(['code' => 'visitor_token_invalid']);
    }

    public function test_banned_visitors_are_not_rejected_by_the_middleware(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor(['is_banned' => true]);

        $this->withVisitor($token)->postJson(self::API.'/forms/post/start', ['board' => $board->slug])->assertOk();
        $this->withVisitor($token)->getJson(self::API.'/me/state')->assertOk();
    }

    public function test_last_seen_is_refreshed_at_most_every_five_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));
        ['token' => $token, 'visitor' => $visitor] = $this->issueVisitor(['last_seen_at' => now()->subHour(), 'last_ip_hash' => str_repeat('0', 32)]);
        $t0 = now();

        $this->withVisitor($token)->getJson(self::API.'/me/state')->assertOk();
        $visitor->refresh();
        $this->assertTrue($visitor->last_seen_at->equalTo($t0));
        $this->assertNotSame(str_repeat('0', 32), $visitor->last_ip_hash);

        Carbon::setTestNow($t0->copy()->addMinutes(2));
        $this->withVisitor($token)->getJson(self::API.'/me/state')->assertOk();
        $this->assertTrue($visitor->fresh()->last_seen_at->equalTo($t0));

        Carbon::setTestNow($t0->copy()->addMinutes(6));
        $this->withVisitor($token)->getJson(self::API.'/me/state')->assertOk();
        $this->assertTrue($visitor->fresh()->last_seen_at->equalTo($t0->copy()->addMinutes(6)));
    }

    // ─── IP hashing ──────────────────────────────────────────────

    public function test_ip_hash_is_32_hex_chars_stable_and_never_the_raw_ip(): void
    {
        $hash = IpHasher::hash('203.0.113.7');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash);
        $this->assertSame($hash, IpHasher::hash('203.0.113.7'));
        $this->assertNotSame($hash, IpHasher::hash('203.0.113.8'));
        $this->assertStringNotContainsString('.', $hash);
    }

    public function test_ip_hash_rotates_monthly(): void
    {
        $march = Carbon::parse('2026-03-15 12:00:00', 'UTC');
        $marchLate = Carbon::parse('2026-03-31 23:59:59', 'UTC');
        $april = Carbon::parse('2026-04-01 00:00:00', 'UTC');

        $this->assertSame(IpHasher::hash('198.51.100.1', $march), IpHasher::hash('198.51.100.1', $marchLate));
        $this->assertNotSame(IpHasher::hash('198.51.100.1', $march), IpHasher::hash('198.51.100.1', $april));

        // the key comes from config: another secret gives another hash
        $before = IpHasher::hash('198.51.100.1', $march);
        config(['roadmap.hash_key' => str_repeat('b', 64)]);
        $this->assertNotSame($before, IpHasher::hash('198.51.100.1', $march));
    }

    public function test_ipv6_is_masked_to_its_64_prefix_and_mapped_ipv4_equals_ipv4(): void
    {
        $this->assertSame(
            IpHasher::hash('2001:db8:aaaa:bbbb:1111:2222:3333:4444'),
            IpHasher::hash('2001:db8:aaaa:bbbb:ffff:eeee:dddd:cccc')
        );
        $this->assertNotSame(
            IpHasher::hash('2001:db8:aaaa:bbbb::1'),
            IpHasher::hash('2001:db8:aaaa:bbbc::1')
        );
        $this->assertSame(IpHasher::hash('192.0.2.5'), IpHasher::hash('::ffff:192.0.2.5'));
    }

    public function test_missing_hash_key_outside_testing_is_a_clear_exception(): void
    {
        config(['roadmap.hash_key' => '']);
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ROADMAP_HASH_KEY');
        IpHasher::hash('192.0.2.5');
    }

    // ─── client IP resolution ────────────────────────────────────

    private function requestFrom(string $remote, ?string $forwarded = null): Request
    {
        $server = ['REMOTE_ADDR' => $remote];
        if ($forwarded !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwarded;
        }

        return Request::create('/x', 'GET', [], [], [], $server);
    }

    public function test_forwarded_header_is_ignored_when_the_peer_is_not_a_trusted_proxy(): void
    {
        $this->assertSame('203.0.113.9', ClientIp::resolve($this->requestFrom('203.0.113.9', '1.2.3.4')));
    }

    public function test_forwarded_header_is_used_from_a_trusted_proxy_taking_the_rightmost_untrusted_entry(): void
    {
        config(['roadmap.trusted_proxies' => ['10.0.0.0/8', '192.168.1.5']]);

        $this->assertSame('8.8.8.8', ClientIp::resolve($this->requestFrom('10.1.2.3', '8.8.8.8')));
        // spoofed left-most entry is skipped: right-most untrusted wins
        $this->assertSame('8.8.8.8', ClientIp::resolve($this->requestFrom('10.1.2.3', '6.6.6.6, 8.8.8.8, 10.9.9.9')));
        $this->assertSame('8.8.8.8', ClientIp::resolve($this->requestFrom('192.168.1.5', '8.8.8.8')));
        // garbage falls back to the peer
        $this->assertSame('10.1.2.3', ClientIp::resolve($this->requestFrom('10.1.2.3', 'not-an-ip')));
        $this->assertSame('10.1.2.3', ClientIp::resolve($this->requestFrom('10.1.2.3')));
    }

    public function test_cidr_matching(): void
    {
        $this->assertTrue(ClientIp::inCidr('172.16.5.4', '172.16.0.0/12'));
        $this->assertFalse(ClientIp::inCidr('172.32.0.1', '172.16.0.0/12'));
        $this->assertTrue(ClientIp::inCidr('::1', '::1/128'));
        $this->assertTrue(ClientIp::inCidr('fd00::5', 'fc00::/7'));
        $this->assertFalse(ClientIp::inCidr('2001:db8::1', 'fc00::/7'));
        $this->assertFalse(ClientIp::inCidr('10.0.0.1', '::1/128'));
        $this->assertTrue(ClientIp::inCidr('192.0.2.1', '192.0.2.1'));
    }

    public function test_a_spoofed_forwarded_header_cannot_dodge_the_ip_token_cap(): void
    {
        $this->setSettings(['limits' => ['tokens_per_ip_day' => 1]]);
        config(['roadmap.trusted_proxies' => []]);   // production has no proxy hop: REMOTE_ADDR is the client

        $this->withHeader('X-Forwarded-For', '1.1.1.1')->postJson(self::API.'/visitor')->assertCreated();
        $this->withHeader('X-Forwarded-For', '2.2.2.2')->postJson(self::API.'/visitor')->assertStatus(429)->assertJson(['code' => 'daily_limit']);
    }

    public function test_content_is_stamped_with_the_hashed_ip_never_the_raw_ip(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $board)->assertCreated();

        $expected = IpHasher::hash('127.0.0.1');
        $this->assertSame($expected, Post::firstOrFail()->ip_hash);
    }

    public function test_prune_command_nulls_old_hashes_and_removes_only_idle_visitors(): void
    {
        config(['roadmap.ip_retention_days' => 30]);
        $board = $this->makeBoard();

        $old = $this->issueVisitor(['last_seen_at' => now()->subDays(200), 'first_seen_at' => now()->subDays(300)]);
        $keepVoter = $this->issueVisitor(['last_seen_at' => now()->subDays(200)]);
        $banned = $this->issueVisitor(['last_seen_at' => now()->subDays(200), 'is_banned' => true]);
        $recent = $this->issueVisitor(['last_seen_at' => now()->subDays(2)]);

        $post = $this->approvedPost($board);
        Vote::create(['post_id' => $post->id, 'visitor_id' => $keepVoter['visitor']->id, 'ip_hash' => str_repeat('a', 32), 'created_at' => now()->subDays(40)]);
        $post->update(['ip_hash' => str_repeat('b', 32)]);
        \DB::table('roadmap_posts')->where('id', $post->id)->update(['created_at' => now()->subDays(40)]);
        AbuseEvent::create(['type' => 'honeypot', 'created_at' => now()->subDays(40)]);
        AbuseEvent::create(['type' => 'honeypot', 'created_at' => now()->subDays(1)]);

        $this->artisan('roadmap:prune')->assertSuccessful();

        $this->assertNull(Post::find($post->id)->ip_hash);
        $this->assertNull(Vote::first()->ip_hash);
        $this->assertSame(1, AbuseEvent::count());

        $this->assertNull(Visitor::find($keepVoter['visitor']->id)->first_ip_hash);
        $this->assertNotNull(Visitor::find($recent['visitor']->id)->first_ip_hash);
        $this->assertNull(Visitor::find($old['visitor']->id));                       // idle, nothing left behind
        $this->assertNotNull(Visitor::find($keepVoter['visitor']->id));              // has a vote
        $this->assertNotNull(Visitor::find($banned['visitor']->id));                 // bans persist
        $this->assertNotNull(Visitor::find($recent['visitor']->id));
    }
}
