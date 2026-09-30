<?php

namespace Tests\Feature\Roadmap;

use App\Events\Roadmap\PostApproved;
use App\Models\Roadmap\AbuseEvent;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class FormGuardTest extends RoadmapTestCase
{
    private function postUrl($board): string
    {
        return self::API.'/boards/'.$board->slug.'/posts';
    }

    // ─── form start ──────────────────────────────────────────────

    public function test_form_start_returns_a_signed_token_and_min_seconds(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $response = $this->withVisitor($token)->postJson(self::API.'/forms/post/start', ['board' => $board->slug])
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['form_token', 'min_seconds', 'max_age_seconds'], 'message']);

        $this->assertSame(5, $response->json('data.min_seconds'));
        $this->assertSame(21600, $response->json('data.max_age_seconds'));
        $this->assertStringContainsString('.', $response->json('data.form_token'));

        $this->setSettings(['moderation' => ['min_comment_seconds' => 9]]);
        $this->withVisitor($token)->postJson(self::API.'/forms/comment/start', ['board' => $board->slug])
            ->assertOk()->assertJsonPath('data.min_seconds', 9);
    }

    public function test_form_start_needs_a_visitor_a_valid_kind_and_a_real_board(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $this->postJson(self::API.'/forms/post/start', ['board' => $board->slug])->assertStatus(401);
        $this->withVisitor($token)->postJson(self::API.'/forms/other/start', ['board' => $board->slug])->assertStatus(404);
        $this->withVisitor($token)->postJson(self::API.'/forms/post/start', ['board' => 'nope'])->assertStatus(404);
        $this->withVisitor($token)->postJson(self::API.'/forms/post/start', [])->assertStatus(422)->assertJsonValidationErrors('board');

        $archived = $this->makeBoard(['is_archived' => true]);
        $this->withVisitor($token)->postJson(self::API.'/forms/post/start', ['board' => $archived->slug])->assertStatus(404);
    }

    // ─── token rules ─────────────────────────────────────────────

    public function test_a_valid_token_after_the_minimum_time_creates_a_pending_post(): void
    {
        $board = $this->makeBoard();
        ['token' => $token, 'visitor' => $visitor] = $this->issueVisitor();

        $response = $this->submitPost($token, $board, [
            'title' => 'Dark mode for the dashboard',
            'body' => 'Please add it',
            'author_name' => 'Sam',
        ])->assertCreated();

        $response->assertJson(['success' => true, 'data' => ['number' => 1, 'slug' => 'dark-mode-for-the-dashboard', 'moderation_state' => 'pending']])
            ->assertJsonPath('data.url_path', '/roadmap/'.$board->slug.'/p/1-dark-mode-for-the-dashboard');

        $post = Post::firstOrFail();
        $this->assertSame('pending', $post->moderation_state);
        $this->assertSame($visitor->id, $post->visitor_id);
        $this->assertSame(32, strlen($post->ip_hash));
        $this->assertNull($post->published_at);
        $this->assertSame($this->statusOf($board, 'under-review')->id, $post->status_id);
        $this->assertSame(1, $visitor->fresh()->posts_count);
        $this->assertSame(0, $visitor->fresh()->approved_posts_count);
        $this->assertSame(2, $board->fresh()->next_post_number);
    }

    public function test_missing_token_is_a_422_and_garbage_or_foreign_tokens_are_form_expired(): void
    {
        $board = $this->makeBoard();
        $other = $this->makeBoard();
        $a = $this->issueVisitor();
        $b = $this->issueVisitor();

        $this->withVisitor($a['token'])->postJson($this->postUrl($board), ['title' => 'A perfectly fine title'])
            ->assertStatus(422)->assertJsonValidationErrors('form_token');

        $this->submitPost($a['token'], $board, ['form_token' => 'garbage'])
            ->assertStatus(400)->assertJson(['code' => 'form_expired', 'success' => false]);

        // tampered signature
        $valid = $this->startForm($a['token'], $board);
        [$payload] = explode('.', $valid);
        $this->submitPost($a['token'], $board, ['form_token' => $payload.'.AAAA'])->assertStatus(400)->assertJson(['code' => 'form_expired']);

        // token of another board
        $this->submitPost($a['token'], $board, ['form_token' => $this->startForm($a['token'], $other)])
            ->assertStatus(400)->assertJson(['code' => 'form_expired']);

        // token of another visitor
        $this->submitPost($a['token'], $board, ['form_token' => $this->startForm($b['token'], $board)])
            ->assertStatus(400)->assertJson(['code' => 'form_expired']);

        // token of the wrong kind
        $this->submitPost($a['token'], $board, ['form_token' => $this->startForm($a['token'], $board, 'comment')])
            ->assertStatus(400)->assertJson(['code' => 'form_expired']);

        $this->assertSame(0, Post::count());
    }

    public function test_too_fast_is_rejected_but_the_same_token_works_afterwards(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();
        $formToken = $this->startForm($token, $board);

        Carbon::setTestNow(now()->addSeconds(2));
        $this->submitPost($token, $board, ['form_token' => $formToken], false)
            ->assertStatus(400)->assertJson(['code' => 'too_fast', 'success' => false, 'data' => null]);
        $this->assertSame(0, Post::count());

        Carbon::setTestNow(now()->addSeconds(10));
        $this->submitPost($token, $board, ['form_token' => $formToken], false)->assertCreated();
        $this->assertSame(1, Post::count());
    }

    public function test_a_token_is_single_use(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();
        $formToken = $this->startForm($token, $board);

        $this->submitPost($token, $board, ['form_token' => $formToken])->assertCreated();
        $this->submitPost($token, $board, ['form_token' => $formToken, 'title' => 'Some other idea entirely'], false)
            ->assertStatus(400)->assertJson(['code' => 'form_expired']);
        $this->assertSame(1, Post::count());
    }

    public function test_an_old_token_expires_after_six_hours(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();
        $formToken = $this->startForm($token, $board);

        Carbon::setTestNow(now()->addHours(6)->addMinute());
        $this->submitPost($token, $board, ['form_token' => $formToken], false)
            ->assertStatus(400)->assertJson(['code' => 'form_expired']);

        $fresh = $this->startForm($token, $board);
        Carbon::setTestNow(now()->addHours(5));
        $this->submitPost($token, $board, ['form_token' => $fresh], false)->assertCreated();
    }

    // ─── honeypot / shadow ban ───────────────────────────────────

    public function test_honeypot_returns_a_fake_201_and_stores_nothing(): void
    {
        $board = $this->makeBoard();
        ['token' => $token, 'visitor' => $visitor] = $this->issueVisitor();

        $this->submitPost($token, $board, ['website' => 'http://spam.example'])
            ->assertCreated()
            ->assertJsonStructure(['success', 'data' => ['number', 'slug', 'moderation_state', 'url_path'], 'message'])
            ->assertJsonPath('data.moderation_state', 'pending');

        $this->assertSame(0, Post::count());
        $this->assertSame(0, $visitor->fresh()->posts_count);
        $this->assertSame(1, $board->fresh()->next_post_number);
        $this->assertDatabaseHas('roadmap_abuse_events', ['type' => 'honeypot', 'visitor_id' => $visitor->id]);
    }

    public function test_honeypot_on_comments_is_a_fake_201(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        ['token' => $token] = $this->issueVisitor();

        $this->submitComment($token, $board, $post, ['website' => 'x'])
            ->assertCreated()->assertJsonPath('data.moderation_state', 'pending');
        $this->assertSame(0, Comment::count());
        $this->assertSame(1, AbuseEvent::where('type', 'honeypot')->count());
    }

    public function test_shadow_banned_visitor_gets_a_fake_success_for_posts_and_comments(): void
    {
        $board = $this->makeBoard(['require_post_approval' => false, 'require_comment_approval' => false]);
        $post = $this->approvedPost($board);
        ['token' => $token, 'visitor' => $visitor] = $this->issueVisitor(['is_banned' => true]);

        $this->submitPost($token, $board)->assertCreated()->assertJsonPath('data.moderation_state', 'pending');
        $this->submitComment($token, $board, $post)->assertCreated()->assertJsonPath('data.moderation_state', 'pending');

        $this->assertSame(1, Post::count());           // only the fixture post
        $this->assertSame(0, Comment::count());
        $this->assertSame(2, AbuseEvent::where('type', 'banned_write')->where('visitor_id', $visitor->id)->count());
    }

    // ─── business rules ──────────────────────────────────────────

    public function test_closed_submissions_and_comments_are_400(): void
    {
        $board = $this->makeBoard(['allow_submissions' => false, 'allow_comments' => false]);
        $post = $this->approvedPost($board);
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $board)->assertStatus(400)->assertJson(['code' => 'submissions_closed']);
        $this->submitComment($token, $board, $post)->assertStatus(400)->assertJson(['code' => 'comments_closed']);
    }

    public function test_duplicate_content_by_the_same_visitor_is_400_but_allowed_after_a_day(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $board, ['title' => 'Please add exports to CSV', 'body' => 'We need it'])->assertCreated();
        $this->submitPost($token, $board, ['title' => 'please  ADD exports to csv!', 'body' => 'We need it'])
            ->assertStatus(400)->assertJson(['code' => 'duplicate_content']);
        $this->assertSame(1, Post::count());

        Carbon::setTestNow(now()->addHours(25));
        $this->submitPost($token, $board, ['title' => 'Please add exports to CSV', 'body' => 'We need it'])->assertCreated();
    }

    public function test_same_content_from_another_visitor_on_the_same_ip_is_flagged_pending(): void
    {
        $board = $this->makeBoard(['require_post_approval' => false]);
        $a = $this->issueVisitor();
        $b = $this->issueVisitor();

        $this->submitPost($a['token'], $board, ['title' => 'A shared idea title', 'body' => 'same body'])
            ->assertCreated()->assertJsonPath('data.moderation_state', 'approved');
        $this->submitPost($b['token'], $board, ['title' => 'A shared idea title', 'body' => 'same body'])
            ->assertCreated()->assertJsonPath('data.moderation_state', 'pending');

        $second = Post::orderByDesc('id')->first();
        $this->assertSame('pending', $second->moderation_state);
        $this->assertContains('dup_ip', $second->flags);
    }

    public function test_blocklist_hit_is_stored_as_spam_but_looks_pending(): void
    {
        $this->setSettings(['moderation' => ['blocklist' => ['viagra', 'crypto giveaway']]]);
        $board = $this->makeBoard(['require_post_approval' => false]);
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $board, ['title' => 'Buy Viagra now cheap', 'body' => 'best price'])
            ->assertCreated()->assertJsonPath('data.moderation_state', 'pending');

        $post = Post::firstOrFail();
        $this->assertSame('spam', $post->moderation_state);
        $this->assertDatabaseHas('roadmap_abuse_events', ['type' => 'spam_keyword']);

        // whole-word only: "viagras" style substrings inside other words do not trigger
        $this->submitPost($token, $board, ['title' => 'Support pharmaviagrafoo imports', 'body' => 'unrelated'])->assertCreated();
        $this->assertSame('approved', Post::orderByDesc('id')->first()->moderation_state);
    }

    public function test_too_many_links_force_pending_with_a_flag(): void
    {
        $board = $this->makeBoard(['require_post_approval' => false]);
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $board, [
            'title' => 'Check out these pages please',
            'body' => 'http://a.example/x http://b.example/y https://c.example/z',
        ])->assertCreated()->assertJsonPath('data.moderation_state', 'pending');

        $post = Post::firstOrFail();
        $this->assertSame('pending', $post->moderation_state);
        $this->assertSame(['links:3'], $post->flags);

        $this->submitPost($token, $board, ['title' => 'One link is fine really', 'body' => 'see https://a.example'])
            ->assertCreated()->assertJsonPath('data.moderation_state', 'approved');
    }

    public function test_moderation_state_rules_and_auto_trust(): void
    {
        Event::fake([PostApproved::class]);

        // boards that do not require approval publish straight away
        $open = $this->makeBoard(['require_post_approval' => false]);
        $a = $this->issueVisitor();
        $this->submitPost($a['token'], $open)->assertCreated()->assertJsonPath('data.moderation_state', 'approved');
        $post = Post::firstOrFail();
        $this->assertNotNull($post->published_at);
        $this->assertSame(1, $a['visitor']->fresh()->approved_posts_count);
        Event::assertDispatched(PostApproved::class, 1);

        // default board: pending for a newcomer...
        $board = $this->makeBoard(['trust_after_approved' => 2]);
        $b = $this->issueVisitor();
        $this->submitPost($b['token'], $board)->assertCreated()->assertJsonPath('data.moderation_state', 'pending');

        // ...auto approved once the visitor has enough approved posts
        $b['visitor']->update(['approved_posts_count' => 2]);
        $this->submitPost($b['token'], $board, ['title' => 'A trusted visitor idea'])->assertCreated()->assertJsonPath('data.moderation_state', 'approved');
        Event::assertDispatched(PostApproved::class, 2);

        // trust is revoked when the visitor has spam/rejected content
        $c = $this->issueVisitor(['approved_posts_count' => 5]);
        $this->makePost($board, ['moderation_state' => 'rejected', 'visitor_id' => $c['visitor']->id]);
        $this->submitPost($c['token'], $board, ['title' => 'Trusted but flagged history'])->assertJsonPath('data.moderation_state', 'pending');

        // trust disabled with null threshold
        $strict = $this->makeBoard(['trust_after_approved' => null]);
        $d = $this->issueVisitor(['approved_posts_count' => 50]);
        $this->submitPost($d['token'], $strict)->assertJsonPath('data.moderation_state', 'pending');
    }

    public function test_comment_moderation_and_counts(): void
    {
        $board = $this->makeBoard(['require_comment_approval' => true]);
        $post = $this->approvedPost($board);
        $a = $this->issueVisitor();

        $this->submitComment($a['token'], $board, $post)->assertCreated()
            ->assertJson(['data' => ['id' => null, 'moderation_state' => 'pending']]);
        $this->assertSame(0, $post->fresh()->comments_count);

        $open = $this->makeBoard(['require_comment_approval' => false]);
        $openPost = $this->approvedPost($open);
        $b = $this->issueVisitor();
        $response = $this->submitComment($b['token'], $open, $openPost)->assertCreated()->assertJsonPath('data.moderation_state', 'approved');
        $this->assertIsInt($response->json('data.id'));
        $this->assertSame(1, $openPost->fresh()->comments_count);
        $this->assertSame(1, $b['visitor']->fresh()->approved_comments_count);
    }

    public function test_reply_parent_must_be_a_top_level_approved_comment_of_the_same_post(): void
    {
        $board = $this->makeBoard(['require_comment_approval' => false]);
        $post = $this->approvedPost($board);
        $otherPost = $this->approvedPost($board);
        ['token' => $token] = $this->issueVisitor();

        $top = Comment::create(['post_id' => $post->id, 'body' => 'top level', 'moderation_state' => 'approved']);
        $reply = Comment::create(['post_id' => $post->id, 'parent_id' => $top->id, 'body' => 'nested', 'moderation_state' => 'approved']);
        $pending = Comment::create(['post_id' => $post->id, 'body' => 'pending one', 'moderation_state' => 'pending']);
        $foreign = Comment::create(['post_id' => $otherPost->id, 'body' => 'other post', 'moderation_state' => 'approved']);

        $this->submitComment($token, $board, $post, ['parent_id' => $top->id, 'body' => 'a valid reply here'])->assertCreated();

        foreach ([$reply->id, $pending->id, $foreign->id, 99999] as $i => $bad) {
            $this->submitComment($token, $board, $post, ['parent_id' => $bad, 'body' => 'bad parent number '.$i])
                ->assertStatus(422)->assertJsonValidationErrors('parent_id');
        }
    }

    public function test_comment_duplicate_and_links(): void
    {
        $board = $this->makeBoard(['require_comment_approval' => false]);
        $post = $this->approvedPost($board);
        ['token' => $token] = $this->issueVisitor();

        $this->submitComment($token, $board, $post, ['body' => 'Same words again'])->assertCreated();
        $this->submitComment($token, $board, $post, ['body' => 'same words again'])->assertStatus(400)->assertJson(['code' => 'duplicate_content']);

        $this->submitComment($token, $board, $post, ['body' => 'see http://a.example and http://b.example'])
            ->assertCreated()->assertJsonPath('data.moderation_state', 'pending');
    }

    // ─── validation & sanitising ─────────────────────────────────

    public function test_validation_rules_for_posts(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $board, ['title' => 'short'])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->submitPost($token, $board, ['title' => str_repeat('a', 141)])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->submitPost($token, $board, ['body' => str_repeat('a', 5001)])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->submitPost($token, $board, ['author_name' => str_repeat('a', 41)])->assertStatus(422)->assertJsonValidationErrors('author_name');
        $this->submitPost($token, $board, ['tag_slugs' => ['a', 'b', 'c', 'd']])->assertStatus(422)->assertJsonValidationErrors('tag_slugs');
        $this->assertSame(0, Post::count());
    }

    public function test_author_names_reject_reserved_words_tags_and_links(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $bad = ['Admin', 'PNE Team', 'Official', 'staff', 'S u p p o r t', 'Moderator', '<b>bob</b>', 'visit http://evil.example', 'www.evil.com', 'shop.example.com'];
        foreach ($bad as $name) {
            Cache::flush(); // the post-submit throttle counts invalid requests too
            $this->submitPost($token, $board, ['author_name' => $name])->assertStatus(422)->assertJsonValidationErrors('author_name');
        }
        Cache::flush();

        $this->submitPost($token, $board, ['author_name' => 'Sam Rivera'])->assertCreated();
    }

    public function test_text_is_sanitised_before_storing(): void
    {
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $board, [
            'title' => "  Zero\u{200B}width   and\u{202E}bidi \u{0007}chars  here ",
            'body' => "Line one\r\n\r\n\r\n\r\nLine   two\u{FEFF}\tend",
            'author_name' => "  Ann\u{200B}a  ",
        ])->assertCreated();

        $post = Post::firstOrFail();
        $this->assertSame('Zerowidth andbidi chars here', $post->title);
        $this->assertSame("Line one\n\nLine two end", $post->body);
        $this->assertSame('Anna', $post->author_name);
    }

    public function test_html_is_kept_as_plain_text_and_tags_are_attached_per_board(): void
    {
        $board = $this->makeBoard();
        $other = $this->makeBoard();
        $tag = Tag::create(['board_id' => $board->id, 'name' => 'UI', 'slug' => 'ui', 'color' => '#111111']);
        Tag::create(['board_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'color' => '#222222']);
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $board, [
            'title' => 'Handle <script>alert(1)</script> titles',
            'tag_slugs' => ['ui', 'foreign', 'ghost'],
        ])->assertCreated();

        $post = Post::firstOrFail();
        $this->assertSame('Handle <script>alert(1)</script> titles', $post->title); // stored as text, rendered as text
        $this->assertSame([$tag->id], $post->tags()->pluck('roadmap_tags.id')->all());
    }

    public function test_post_numbers_increase_per_board(): void
    {
        $a = $this->makeBoard(['require_post_approval' => false]);
        $b = $this->makeBoard(['require_post_approval' => false]);
        ['token' => $token] = $this->issueVisitor();

        $this->submitPost($token, $a, ['title' => 'first idea on board A'])->assertJsonPath('data.number', 1);
        Carbon::setTestNow(now()->addMinutes(2));
        $this->submitPost($token, $a, ['title' => 'second idea on board A'])->assertJsonPath('data.number', 2);
        $this->submitPost($token, $b, ['title' => 'first idea on board B'])->assertJsonPath('data.number', 1);
    }
}
