<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\SlugRedirect;
use App\Models\Roadmap\Tag;
use App\Services\Roadmap\PostCounters;
use Illuminate\Support\Carbon;

/** pending / rejected / spam / merged content must never surface publicly. */
class ModerationVisibilityTest extends RoadmapTestCase
{
    private function base(string $suffix = ''): string
    {
        return self::API.$suffix;
    }

    public function test_only_approved_unmerged_posts_are_listed_counted_and_suggested(): void
    {
        $board = $this->makeBoard();
        $planned = $this->statusOf($board, 'planned');

        $this->approvedPost($board, ['title' => 'Visible export idea', 'status_id' => $planned->id]);
        $this->makePost($board, ['title' => 'Pending export idea', 'moderation_state' => 'pending', 'status_id' => $planned->id]);
        $this->makePost($board, ['title' => 'Rejected export idea', 'moderation_state' => 'rejected', 'status_id' => $planned->id]);
        $this->makePost($board, ['title' => 'Spam export idea', 'moderation_state' => 'spam', 'status_id' => $planned->id]);
        $target = $this->approvedPost($board, ['title' => 'Merge target export']);
        $this->approvedPost($board, ['title' => 'Merged export idea', 'merged_into_post_id' => $target->id, 'status_id' => $planned->id]);

        $list = $this->getJson($this->base('/boards/'.$board->slug.'/posts'))->assertOk();
        $titles = collect($list->json('data'))->pluck('title')->all();
        sort($titles);
        $this->assertSame(['Merge target export', 'Visible export idea'], $titles);
        $this->assertSame(2, $list->json('pagination.total'));

        // search does not surface hidden posts either
        $found = $this->getJson($this->base('/boards/'.$board->slug.'/posts?q=export'))->json('data');
        $this->assertCount(2, $found);

        // similar
        $similar = $this->getJson($this->base('/boards/'.$board->slug.'/posts/similar?q=export'))->assertOk()->json('data');
        $this->assertCount(2, $similar);
        $this->assertNotContains('Pending export idea', array_column($similar, 'title'));

        // board summaries and counts
        $this->assertSame(2, $this->getJson($this->base('/boards'))->json('data.0.posts_count'));
        $detail = $this->getJson($this->base('/boards/'.$board->slug))->assertOk();
        $this->assertSame(2, $detail->json('data.board.posts_count'));
        $plannedRow = collect($detail->json('data.statuses'))->firstWhere('slug', 'planned');
        $this->assertSame(1, $plannedRow['posts_count']);

        // roadmap columns
        $columns = $this->getJson($this->base('/boards/'.$board->slug.'/roadmap'))->assertOk()->json('data.columns');
        $col = collect($columns)->first(fn ($c) => $c['status']['slug'] === 'planned');
        $this->assertSame(1, $col['total']);
        $this->assertSame(['Visible export idea'], array_column($col['posts'], 'title'));
    }

    public function test_hidden_posts_are_404_for_anonymous_and_other_visitors(): void
    {
        $board = $this->makeBoard();
        $owner = $this->issueVisitor();
        $stranger = $this->issueVisitor();

        $posts = [];
        foreach (['pending', 'rejected', 'spam'] as $state) {
            $posts[$state] = $this->makePost($board, ['moderation_state' => $state, 'visitor_id' => $owner['visitor']->id]);
        }

        foreach ($posts as $post) {
            $url = $this->base('/boards/'.$board->slug.'/posts/'.$post->number);
            $this->getJson($url)->assertStatus(404)->assertJson(['success' => false, 'data' => null, 'code' => 'not_found']);
            $this->withVisitor($stranger['token'])->getJson($url)->assertStatus(404);
            $this->flushHeaders();
            $this->getJson($url.'/comments')->assertStatus(404);
        }

        // rejected stays hidden even for the owner
        $this->withVisitor($owner['token'])->getJson($this->base('/boards/'.$board->slug.'/posts/'.$posts['rejected']->number))->assertStatus(404);
    }

    public function test_owner_sees_own_pending_and_spam_as_pending_with_no_store(): void
    {
        $board = $this->makeBoard();
        $owner = $this->issueVisitor();

        foreach (['pending', 'spam'] as $state) {
            $post = $this->makePost($board, ['moderation_state' => $state, 'visitor_id' => $owner['visitor']->id]);

            $response = $this->withVisitor($owner['token'])
                ->getJson($this->base('/boards/'.$board->slug.'/posts/'.$post->number))
                ->assertOk()
                ->assertJsonPath('data.viewer.is_owner_pending', true)
                ->assertJsonPath('data.viewer.moderation_state', 'pending');

            $cache = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cache);
            $this->assertStringContainsString('private', $cache);
            $this->assertNull($response->headers->get('ETag'));
        }
    }

    public function test_approved_post_detail_is_cacheable_and_visitor_agnostic(): void
    {
        $board = $this->makeBoard();
        $owner = $this->issueVisitor();
        $post = $this->approvedPost($board, ['visitor_id' => $owner['visitor']->id]);
        $url = $this->base('/boards/'.$board->slug.'/posts/'.$post->number);

        $anon = $this->getJson($url)->assertOk();
        $asOwner = $this->withVisitor($owner['token'])->getJson($url)->assertOk();

        $this->assertSame($anon->json('data'), $asOwner->json('data'));
        $this->assertStringContainsString('public', (string) $anon->headers->get('Cache-Control'));
        $this->assertFalse($anon->json('data.viewer.is_owner_pending'));
        $this->assertNull($anon->json('data.viewer.moderation_state'));
    }

    public function test_merged_post_detail_points_at_its_target(): void
    {
        $board = $this->makeBoard();
        $target = $this->approvedPost($board, ['title' => 'The surviving idea']);
        $merged = $this->approvedPost($board, ['merged_into_post_id' => $target->id]);

        $this->getJson($this->base('/boards/'.$board->slug.'/posts/'.$merged->number))
            ->assertOk()
            ->assertJsonPath('data.merged_into', ['board_slug' => $board->slug, 'number' => $target->number, 'slug' => $target->slug]);

        // a pending target is not advertised
        $hiddenTarget = $this->makePost($board, ['moderation_state' => 'pending']);
        $orphan = $this->approvedPost($board, ['merged_into_post_id' => $hiddenTarget->id]);
        $this->getJson($this->base('/boards/'.$board->slug.'/posts/'.$orphan->number))->assertOk()->assertJsonPath('data.merged_into', null);
    }

    public function test_only_approved_comments_are_listed_counted_and_nested(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);

        $top = Comment::create(['post_id' => $post->id, 'body' => 'approved top', 'moderation_state' => 'approved', 'author_name' => 'Sam']);
        Comment::create(['post_id' => $post->id, 'body' => 'pending top', 'moderation_state' => 'pending']);
        Comment::create(['post_id' => $post->id, 'body' => 'spam top', 'moderation_state' => 'spam']);
        Comment::create(['post_id' => $post->id, 'body' => 'rejected top', 'moderation_state' => 'rejected']);
        Comment::create(['post_id' => $post->id, 'parent_id' => $top->id, 'body' => 'approved reply', 'moderation_state' => 'approved']);
        Comment::create(['post_id' => $post->id, 'parent_id' => $top->id, 'body' => 'pending reply', 'moderation_state' => 'pending']);
        $hiddenParent = Comment::create(['post_id' => $post->id, 'body' => 'hidden parent', 'moderation_state' => 'pending']);
        Comment::create(['post_id' => $post->id, 'parent_id' => $hiddenParent->id, 'body' => 'orphan reply', 'moderation_state' => 'approved']);

        app(PostCounters::class)->recountComments($post->id);
        $this->assertSame(3, $post->fresh()->comments_count); // approved top + approved reply + orphan reply (all approved)

        $response = $this->getJson($this->base('/boards/'.$board->slug.'/posts/'.$post->number.'/comments'))->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('approved top', $data[0]['body']);
        $this->assertCount(1, $data[0]['replies']);
        $this->assertSame('approved reply', $data[0]['replies'][0]['body']);
        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertStringNotContainsString('pending', json_encode($data));
        $this->assertStringNotContainsString('spam', json_encode($data));
    }

    public function test_comment_pagination_is_30_per_page_oldest_first(): void
    {
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        for ($i = 1; $i <= 35; $i++) {
            Comment::unguarded(fn () => Comment::create(['post_id' => $post->id, 'body' => 'comment '.$i, 'moderation_state' => 'approved', 'created_at' => now()->addSeconds($i)]));
        }

        $url = $this->base('/boards/'.$board->slug.'/posts/'.$post->number.'/comments');
        $page1 = $this->getJson($url)->assertOk();
        $this->assertCount(30, $page1->json('data'));
        $this->assertSame('comment 1', $page1->json('data.0.body'));
        $this->assertSame(35, $page1->json('pagination.total'));
        $this->assertSame(2, $page1->json('pagination.last_page'));

        $page2 = $this->getJson($url.'?page=2')->assertOk();
        $this->assertCount(5, $page2->json('data'));
        $this->assertSame('comment 31', $page2->json('data.0.body'));
    }

    public function test_me_state_lists_own_pending_but_only_public_votes(): void
    {
        $board = $this->makeBoard();
        $me = $this->issueVisitor();
        $public = $this->approvedPost($board);
        $hidden = $this->makePost($board, ['moderation_state' => 'pending', 'visitor_id' => $me['visitor']->id]);
        $spam = $this->makePost($board, ['moderation_state' => 'spam', 'visitor_id' => $me['visitor']->id, 'title' => 'Spammy thing here']);
        $rejected = $this->makePost($board, ['moderation_state' => 'rejected', 'visitor_id' => $me['visitor']->id]);

        $this->withVisitor($me['token'])->postJson($this->base('/boards/'.$board->slug.'/posts/'.$public->number.'/vote'), ['voted' => true])->assertOk();
        Comment::create(['post_id' => $public->id, 'visitor_id' => $me['visitor']->id, 'body' => 'my pending comment', 'moderation_state' => 'pending']);

        $response = $this->withVisitor($me['token'])->getJson($this->base('/me/state?board='.$board->slug))->assertOk();
        $this->assertSame([$public->number], $response->json('data.voted_post_numbers'));

        $pending = collect($response->json('data.own_pending'));
        $this->assertEqualsCanonicalizing([$hidden->number, $spam->number], $pending->where('type', 'post')->pluck('number')->all());
        $this->assertSame(1, $pending->where('type', 'comment')->count());
        $this->assertNotContains($rejected->number, $pending->where('type', 'post')->pluck('number')->all());

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
    }

    public function test_me_state_for_unknown_or_missing_token_is_empty_not_an_error(): void
    {
        $board = $this->makeBoard();

        foreach ([null, 'rmv_unknown'] as $token) {
            $request = $token ? $this->withVisitor($token) : $this;
            $request->getJson($this->base('/me/state?board='.$board->slug))
                ->assertOk()
                ->assertJson(['success' => true, 'data' => ['voted_post_numbers' => [], 'own_pending' => []]]);
        }
    }

    public function test_archived_boards_do_not_exist_publicly(): void
    {
        $board = $this->makeBoard(['is_archived' => true]);
        $this->approvedPost($board);

        $this->assertSame([], $this->getJson($this->base('/boards'))->assertOk()->json('data'));
        $this->getJson($this->base('/boards/'.$board->slug))->assertStatus(404);
        $this->getJson($this->base('/boards/'.$board->slug.'/posts'))->assertStatus(404);
        $this->getJson($this->base('/boards/'.$board->slug.'/roadmap'))->assertStatus(404);
    }

    public function test_changelog_shows_only_live_entries_and_visible_linked_posts(): void
    {
        $board = $this->makeBoard();
        $visible = $this->approvedPost($board, ['title' => 'Shipped feature idea']);
        $pending = $this->makePost($board, ['moderation_state' => 'pending', 'title' => 'Hidden linked idea']);

        $live = ChangelogEntry::create([
            'title' => 'Live entry', 'slug' => 'live-entry', 'label' => 'new', 'summary' => 'sum',
            'body_md' => 'x', 'body_html' => '<p>x</p>', 'status' => 'published', 'published_at' => now()->subHour(),
        ]);
        $live->posts()->attach([$visible->id, $pending->id]);

        ChangelogEntry::create(['title' => 'Draft entry', 'slug' => 'draft-entry', 'label' => 'new', 'body_html' => '<p>d</p>', 'status' => 'draft', 'published_at' => now()->subHour()]);
        ChangelogEntry::create(['title' => 'Scheduled entry', 'slug' => 'scheduled-entry', 'label' => 'fixed', 'body_html' => '<p>s</p>', 'status' => 'published', 'published_at' => now()->addDay()]);
        ChangelogEntry::create(['title' => 'Unpublished no date', 'slug' => 'nodate', 'label' => 'fixed', 'body_html' => '<p>s</p>', 'status' => 'published', 'published_at' => null]);

        $list = $this->getJson($this->base('/changelog'))->assertOk();
        $this->assertSame(['live-entry'], array_column($list->json('data'), 'slug'));

        foreach (['draft-entry', 'scheduled-entry', 'nodate'] as $slug) {
            $this->getJson($this->base('/changelog/'.$slug))->assertStatus(404);
        }

        $detail = $this->getJson($this->base('/changelog/live-entry'))->assertOk();
        $this->assertSame(['Shipped feature idea'], array_column($detail->json('data.related_posts'), 'title'));

        // the linked post shows the live entry (and only live ones) on its own page
        $post = $this->getJson($this->base('/boards/'.$board->slug.'/posts/'.$visible->number))->assertOk();
        $this->assertSame(['live-entry'], array_column($post->json('data.related_changelog'), 'slug'));

        // scheduled entry becomes visible once its date passes
        Carbon::setTestNow(now()->addDays(2));
        $this->assertContains('scheduled-entry', array_column($this->getJson($this->base('/changelog'))->json('data'), 'slug'));
    }

    public function test_changelog_old_slug_redirects_resolve_and_rss_only_lists_live_entries(): void
    {
        $entry = ChangelogEntry::create(['title' => 'Renamed', 'slug' => 'new-name', 'label' => 'improved', 'body_html' => '<p>b</p>', 'status' => 'published', 'published_at' => now()->subDay()]);
        SlugRedirect::create(['kind' => 'changelog', 'old_key' => 'old-name', 'target_id' => $entry->id, 'created_at' => now()]);
        ChangelogEntry::create(['title' => 'Secret draft', 'slug' => 'secret-draft', 'label' => 'new', 'body_html' => '<p>b</p>', 'status' => 'draft']);

        $this->getJson($this->base('/changelog/old-name'))->assertOk()->assertJsonPath('data.slug', 'new-name');

        $rss = $this->get($this->base('/changelog/feed.xml'))->assertOk();
        $this->assertStringContainsString('application/rss+xml', (string) $rss->headers->get('Content-Type'));
        $this->assertStringContainsString('https://app.test/changelog/new-name', $rss->getContent());
        $this->assertStringNotContainsString('secret-draft', $rss->getContent());
    }

    public function test_tag_and_status_filters_only_touch_public_posts(): void
    {
        $board = $this->makeBoard();
        $tag = Tag::create(['board_id' => $board->id, 'name' => 'UI', 'slug' => 'ui', 'color' => '#111111']);
        $a = $this->approvedPost($board);
        $b = $this->makePost($board, ['moderation_state' => 'pending']);
        $a->tags()->attach($tag->id);
        $b->tags()->attach($tag->id);

        $ids = $this->getJson($this->base('/boards/'.$board->slug.'/posts?tag[]=ui'))->assertOk()->json('data');
        $this->assertSame([$a->number], array_column($ids, 'number'));

        $detail = $this->getJson($this->base('/boards/'.$board->slug))->json('data.tags.0');
        $this->assertSame(1, $detail['posts_count']);
    }
}
