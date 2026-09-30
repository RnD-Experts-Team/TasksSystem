<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Vote;
use Illuminate\Support\Facades\DB;

/** Trending sort and LIKE search must run on sqlite (tests) exactly as on MySQL (production). */
class SqliteCompatTest extends RoadmapTestCase
{
    private function titles(string $query, $board): array
    {
        return array_column($this->getJson(self::API.'/boards/'.$board->slug.'/posts?'.$query)->assertOk()->json('data'), 'title');
    }

    private function comment(array $attributes): Comment
    {
        return Comment::unguarded(fn () => Comment::create($attributes));
    }

    private function addVotes(Post $post, int $recent, int $old = 0): void
    {
        for ($i = 0; $i < $recent + $old; $i++) {
            ['visitor' => $v] = $this->issueVisitor();
            Vote::create([
                'post_id' => $post->id, 'visitor_id' => $v->id,
                'created_at' => $i < $recent ? now()->subDay() : now()->subDays(30),
            ]);
        }
        $post->update(['votes_count' => $recent + $old]);
    }

    public function test_the_suite_really_runs_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    public function test_trending_ranks_recent_activity_over_lifetime_votes(): void
    {
        $board = $this->makeBoard();
        $old = $this->approvedPost($board, ['title' => 'Old favourite']);
        $hot = $this->approvedPost($board, ['title' => 'Hot right now']);
        $chatty = $this->approvedPost($board, ['title' => 'Chatty thread']);
        $quiet = $this->approvedPost($board, ['title' => 'Quiet one']);

        $this->addVotes($old, 0, 20);                // 20 votes but all a month old
        $this->addVotes($hot, 3);                    // score 6
        $this->addVotes($chatty, 1);                 // 2 + 5 comments = 7
        for ($i = 0; $i < 5; $i++) {
            $this->comment(['post_id' => $chatty->id, 'body' => 'recent '.$i, 'moderation_state' => 'approved', 'created_at' => now()->subHours(2)]);
        }
        $this->comment(['post_id' => $chatty->id, 'body' => 'pending does not count', 'moderation_state' => 'pending', 'created_at' => now()]);
        $this->comment(['post_id' => $quiet->id, 'body' => 'old comment', 'moderation_state' => 'approved', 'created_at' => now()->subDays(20)]);

        $this->assertSame(
            ['Chatty thread', 'Hot right now', 'Old favourite', 'Quiet one'],
            $this->titles('sort=trending', $board)
        );
        // top uses lifetime votes
        $this->assertSame('Old favourite', $this->titles('sort=top', $board)[0]);
    }

    public function test_trending_ties_break_by_votes_then_id_and_pinned_come_first(): void
    {
        $board = $this->makeBoard();
        $a = $this->approvedPost($board, ['title' => 'A', 'votes_count' => 5]);
        $b = $this->approvedPost($board, ['title' => 'B', 'votes_count' => 5]);
        $c = $this->approvedPost($board, ['title' => 'C', 'votes_count' => 9]);
        $pinned = $this->approvedPost($board, ['title' => 'Pinned', 'is_pinned' => true, 'votes_count' => 0]);

        $this->assertSame(['Pinned', 'C', 'B', 'A'], $this->titles('sort=trending', $board));
        $this->assertSame(['Pinned', 'B', 'A', 'C'][0], $this->titles('sort=new', $board)[0]);
    }

    public function test_sort_new_orders_by_publication_date(): void
    {
        $board = $this->makeBoard();
        $this->approvedPost($board, ['title' => 'Oldest', 'published_at' => now()->subDays(3)]);
        $this->approvedPost($board, ['title' => 'Newest', 'published_at' => now()->subDay()]);
        $this->approvedPost($board, ['title' => 'Middle', 'published_at' => now()->subDays(2)]);

        $this->assertSame(['Newest', 'Middle', 'Oldest'], $this->titles('sort=new', $board));
    }

    public function test_search_matches_title_or_body_with_all_tokens(): void
    {
        $board = $this->makeBoard();
        $this->approvedPost($board, ['title' => 'Export to CSV', 'body' => 'spreadsheet please']);
        $this->approvedPost($board, ['title' => 'Dark mode', 'body' => 'export the theme too']);
        $this->approvedPost($board, ['title' => 'Unrelated', 'body' => 'nothing here']);

        $this->assertEqualsCanonicalizing(['Export to CSV', 'Dark mode'], $this->titles('q=export', $board));
        $this->assertSame(['Export to CSV'], $this->titles('q='.urlencode('EXPORT spreadsheet'), $board));   // AND across tokens, case insensitive
        $this->assertSame([], $this->titles('q=zzzz', $board));
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $board = $this->makeBoard();
        $this->approvedPost($board, ['title' => '100% coverage wanted', 'body' => 'plain']);
        $this->approvedPost($board, ['title' => 'Snake_case names', 'body' => 'plain']);
        $this->approvedPost($board, ['title' => 'Something entirely different', 'body' => 'plain']);
        $this->approvedPost($board, ['title' => 'Bang! in the title', 'body' => 'plain']);

        $this->assertSame(['100% coverage wanted'], $this->titles('q='.urlencode('100%'), $board));
        $this->assertSame(['Snake_case names'], $this->titles('q='.urlencode('snake_case'), $board));
        $this->assertSame(['100% coverage wanted'], $this->titles('q='.urlencode('%'), $board));   // a bare % is a literal percent, not a wildcard
        $this->assertSame(['Snake_case names'], $this->titles('q=_', $board));   // literal underscore only
        $this->assertSame(['Bang! in the title'], $this->titles('q='.urlencode('bang!'), $board));
        $this->assertSame([], $this->titles('q='.urlencode('\\'), $board));
    }

    public function test_search_is_limited_to_six_tokens(): void
    {
        $board = $this->makeBoard();
        $this->approvedPost($board, ['title' => 'one two three four five six', 'body' => 'x']);

        // a seventh (non matching) token is ignored
        $this->assertSame(['one two three four five six'], $this->titles('q='.urlencode('one two three four five six nomatch'), $board));
    }

    public function test_similar_reranks_title_overlap_above_body_overlap_then_votes(): void
    {
        $board = $this->makeBoard();
        $this->approvedPost($board, ['title' => 'Something else', 'body' => 'dark mode is mentioned in the body', 'votes_count' => 50]);
        $this->approvedPost($board, ['title' => 'Dark mode please', 'body' => 'x', 'votes_count' => 1]);
        $this->approvedPost($board, ['title' => 'Dark theme', 'body' => 'x', 'votes_count' => 9]);
        $this->approvedPost($board, ['title' => 'No overlap', 'body' => 'x', 'votes_count' => 99]);

        $similar = $this->getJson(self::API.'/boards/'.$board->slug.'/posts/similar?q='.urlencode('dark mode'))->assertOk()->json('data');

        // title "Dark mode please" scores (3+3); "Dark theme" 3; body-only match: 1+1 = 2
        $this->assertSame(['Dark mode please', 'Dark theme', 'Something else'], array_column($similar, 'title'));
    }

    public function test_similar_returns_at_most_five_and_validates_q_length(): void
    {
        $board = $this->makeBoard();
        for ($i = 0; $i < 8; $i++) {
            $this->approvedPost($board, ['title' => 'Exports idea '.$i]);
        }

        $this->assertCount(5, $this->getJson(self::API.'/boards/'.$board->slug.'/posts/similar?q=exports')->json('data'));
        $this->getJson(self::API.'/boards/'.$board->slug.'/posts/similar?q=ab')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->getJson(self::API.'/boards/'.$board->slug.'/posts/similar')->assertStatus(422);
        $this->getJson(self::API.'/boards/'.$board->slug.'/posts/similar?q='.str_repeat('a', 141))->assertStatus(422);
    }

    public function test_filters_by_status_and_pagination(): void
    {
        $board = $this->makeBoard();
        $planned = $this->statusOf($board, 'planned');
        for ($i = 1; $i <= 17; $i++) {
            $this->approvedPost($board, ['title' => 'Idea '.$i, 'votes_count' => $i, 'status_id' => $i % 2 ? $planned->id : $this->statusOf($board, 'live')->id]);
        }

        $page1 = $this->getJson(self::API.'/boards/'.$board->slug.'/posts?per_page=10')->assertOk();
        $this->assertCount(10, $page1->json('data'));
        $this->assertSame(17, $page1->json('pagination.total'));
        $this->assertSame(2, $page1->json('pagination.last_page'));
        $this->assertSame('Idea 17', $page1->json('data.0.title'));
        $this->assertCount(7, $this->getJson(self::API.'/boards/'.$board->slug.'/posts?per_page=10&page=2')->json('data'));

        $onlyPlanned = $this->getJson(self::API.'/boards/'.$board->slug.'/posts?status[]=planned&per_page=30')->json('data');
        $this->assertCount(9, $onlyPlanned);
        $this->assertSame(['planned'], array_values(array_unique(array_column(array_column($onlyPlanned, 'status'), 'slug'))));
    }

    public function test_roadmap_columns_order_pinned_then_roadmap_order_then_votes_and_cap_per_column(): void
    {
        $board = $this->makeBoard();
        $planned = $this->statusOf($board, 'planned');
        foreach ([['A', 0, 1, false], ['B', 2, 9, false], ['C', 1, 5, false], ['D', 1, 8, false], ['P', 5, 0, true]] as [$t, $order, $votes, $pin]) {
            $this->approvedPost($board, ['title' => $t, 'status_id' => $planned->id, 'roadmap_order' => $order, 'votes_count' => $votes, 'is_pinned' => $pin]);
        }

        $columns = $this->getJson(self::API.'/boards/'.$board->slug.'/roadmap?per_column=3')->assertOk()->json('data.columns');
        $col = collect($columns)->first(fn ($c) => $c['status']['slug'] === 'planned');

        $this->assertSame(5, $col['total']);
        $this->assertSame(['P', 'A', 'D'], array_column($col['posts'], 'title'));
        $this->assertSame(['planned', 'in-progress', 'live'], array_column(array_column($columns, 'status'), 'slug'));   // only roadmap columns, in order
        $this->getJson(self::API.'/boards/'.$board->slug.'/roadmap?per_column=21')->assertStatus(422);
    }
}
