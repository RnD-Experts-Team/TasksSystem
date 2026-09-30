<?php

namespace Tests\Feature\Roadmap;

/**
 * Duplicate suggestions must be relevant: filler words are ignored and a candidate has to share
 * at least half of the meaningful words, so one common word does not surface unrelated ideas.
 */
class SimilarPostsTest extends RoadmapTestCase
{
    private function titles(string $slug, string $q): array
    {
        return collect($this->getJson(self::API.'/boards/'.$slug.'/posts/similar?q='.urlencode($q))
            ->assertOk()->json('data'))->pluck('title')->all();
    }

    public function test_an_unrelated_idea_sharing_one_common_word_is_not_suggested(): void
    {
        $board = $this->makeBoard();
        $this->makePost($board, ['title' => 'Export tasks and reports to CSV', 'body' => 'Share progress in spreadsheets.', 'votes_count' => 27]);
        $this->makePost($board, ['title' => 'Slack notifications for assigned tasks', 'body' => 'Send a message to a Slack channel.', 'votes_count' => 20]);

        $titles = $this->titles($board->slug, 'Export tasks to CSV from any list');

        $this->assertSame(['Export tasks and reports to CSV'], $titles);
    }

    public function test_filler_words_alone_do_not_match_anything(): void
    {
        $board = $this->makeBoard();
        $this->makePost($board, ['title' => 'Something to look at from here', 'body' => 'Nothing else.']);

        // "to", "from", "any", "the" are ignored, only "list" remains meaningful
        $this->assertSame([], $this->titles($board->slug, 'to from any the list'));
    }

    public function test_a_single_meaningful_word_still_finds_matches(): void
    {
        $board = $this->makeBoard();
        $this->makePost($board, ['title' => 'Dark mode for the whole app', 'body' => 'Night use.', 'votes_count' => 35]);

        $this->assertSame(['Dark mode for the whole app'], $this->titles($board->slug, 'dark'));
    }

    public function test_short_queries_made_only_of_short_words_fall_back_to_all_tokens(): void
    {
        $board = $this->makeBoard();
        $this->makePost($board, ['title' => 'Better UI and UX polish', 'body' => 'Polish.']);

        // "ui" and "ux" are both below the meaningful-word length, so all tokens are kept
        $this->assertSame(['Better UI and UX polish'], $this->titles($board->slug, 'ui ux'));
    }

    public function test_pending_and_merged_posts_are_never_suggested(): void
    {
        $board = $this->makeBoard();
        $this->makePost($board, ['title' => 'Export everything to CSV', 'moderation_state' => 'pending']);
        $target = $this->makePost($board, ['title' => 'Export all data as CSV']);
        $this->makePost($board, ['title' => 'Export lists to CSV file', 'merged_into_post_id' => $target->id]);

        $this->assertSame(['Export all data as CSV'], $this->titles($board->slug, 'export csv'));
    }
}
