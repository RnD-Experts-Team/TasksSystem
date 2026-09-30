<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\StatusChange;
use App\Models\Roadmap\Tag;
use App\Models\Roadmap\Vote;
use App\Models\User;

/**
 * The public API is a whitelist: exact key sets per shape, and no internal field anywhere
 * in any response (recursively).
 */
class PublicLeakTest extends RoadmapTestCase
{
    private const FORBIDDEN_KEYS = [
        'visitor_id', 'visitor', 'ip_hash', 'first_ip_hash', 'last_ip_hash', 'ua_hash', 'token_hash', 'token',
        'user_id', 'created_by_user_id', 'created_by', 'updated_by', 'responded_by', 'moderated_by', 'moderated_at',
        'moderation_reason', 'content_hash', 'flags', 'email', 'board_id', 'status_id', 'from_status_id', 'to_status_id',
        'changed_by', 'merged_into_post_id', 'parent_post_id', 'original_post_id', 'is_banned', 'is_trusted', 'response_md',
        'body_md', 'roadmap_order', 'next_post_number', 'require_post_approval', 'require_comment_approval',
        'trust_after_approved', 'is_archived', 'sort_order', 'is_admin', 'last_activity_at', 'created_at_raw',
        'updated_at', 'deleted_at', 'banned_reason', 'banned_at', 'banned_by', 'first_seen_at', 'last_seen_at',
        'posts_count_internal', 'approved_posts_count', 'approved_comments_count', 'voters', 'votes', 'password',
    ];

    private array $secrets = [];

    private function walk(mixed $value, string $path, array &$hits): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                    $hits[] = $path.'.'.$key;
                }
                $this->walk($child, $path.'.'.$key, $hits);
            }
        }
    }

    private function assertClean($response, string $label): void
    {
        $body = $response->getContent();
        $decoded = json_decode((string) $body, true);
        $this->assertIsArray($decoded, $label.' is not JSON');

        $hits = [];
        $this->walk($decoded, $label, $hits);
        $this->assertSame([], $hits, $label.' leaks forbidden keys');

        foreach ($this->secrets as $name => $secret) {
            $this->assertStringNotContainsString($secret, (string) $body, $label.' leaks '.$name);
        }
    }

    private function keys(array $item): array
    {
        $keys = array_keys($item);
        sort($keys);

        return $keys;
    }

    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    private function fixture(): array
    {
        $board = $this->makeBoard(['slug' => 'leak-board']);
        $admin = User::factory()->create(['name' => 'Secret Admin Name', 'email' => 'secret-admin@example.com']);
        $author = $this->issueVisitor();
        $voter = $this->issueVisitor();

        $tag = Tag::create(['board_id' => $board->id, 'name' => 'UI', 'slug' => 'ui', 'color' => '#111111']);
        $planned = $this->statusOf($board, 'planned');

        $post = $this->approvedPost($board, [
            'title' => 'A fully loaded idea',
            'body' => 'Full body text',
            'author_name' => 'Sam',
            'visitor_id' => $author['visitor']->id,
            'ip_hash' => str_repeat('c', 32),
            'flags' => ['links:3'],
            'status_id' => $planned->id,
            'votes_count' => 1,
            'comments_count' => 2,
            'is_pinned' => true,
            'response_md' => '**hi**',
            'response_html' => '<p><strong>hi</strong></p>',
            'responded_at' => now(),
            'responded_by' => $admin->id,
            'moderated_by' => $admin->id,
            'moderation_reason' => 'looks fine',
        ]);
        $post->tags()->attach($tag->id);
        Vote::create(['post_id' => $post->id, 'visitor_id' => $voter['visitor']->id, 'ip_hash' => str_repeat('d', 32), 'created_at' => now()]);

        StatusChange::create([
            'post_id' => $post->id, 'from_status_id' => $this->statusOf($board, 'under-review')->id, 'to_status_id' => $planned->id,
            'changed_by' => $admin->id, 'note' => 'Public note', 'is_public' => true, 'created_at' => now(),
        ]);
        StatusChange::create([
            'post_id' => $post->id, 'from_status_id' => $planned->id, 'to_status_id' => $this->statusOf($board, 'in-progress')->id,
            'changed_by' => $admin->id, 'note' => 'Private staff note', 'is_public' => false, 'created_at' => now()->addMinute(),
        ]);

        $top = Comment::create([
            'post_id' => $post->id, 'visitor_id' => $author['visitor']->id, 'author_name' => 'Sam', 'body' => 'visitor comment',
            'moderation_state' => 'approved', 'ip_hash' => str_repeat('e', 32), 'content_hash' => str_repeat('f', 40), 'flags' => ['x'],
        ]);
        Comment::create([
            'post_id' => $post->id, 'parent_id' => $top->id, 'user_id' => $admin->id, 'is_admin' => true, 'author_name' => 'Secret Admin Name',
            'body' => 'team reply', 'moderation_state' => 'approved',
        ]);

        $entry = ChangelogEntry::create([
            'board_id' => $board->id, 'title' => 'Shipped', 'slug' => 'shipped', 'summary' => 'sum', 'label' => 'new', 'body_md' => 'secret md',
            'body_html' => '<p>ok</p>', 'status' => 'published', 'published_at' => now()->subHour(), 'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $entry->posts()->attach($post->id);

        $this->secrets = [
            'author visitor id' => $author['visitor']->id,
            'voter visitor id' => $voter['visitor']->id,
            'author token hash' => $author['visitor']->token_hash,
            'author token' => $author['token'],
            'post ip hash' => str_repeat('c', 32),
            'vote ip hash' => str_repeat('d', 32),
            'comment ip hash' => str_repeat('e', 32),
            'content hash' => str_repeat('f', 40),
            'admin name' => 'Secret Admin Name',
            'admin email' => 'secret-admin@example.com',
            'private note' => 'Private staff note',
            'response markdown' => '**hi**',
            'changelog markdown' => 'secret md',
            'moderation reason' => 'looks fine',
        ];

        return compact('board', 'post', 'author', 'voter', 'entry');
    }

    public function test_every_public_read_has_exact_keys_and_leaks_nothing(): void
    {
        ['board' => $board, 'post' => $post, 'author' => $author] = $this->fixture();
        $base = self::API;

        // ─── config
        $config = $this->getJson($base.'/config')->assertOk();
        $this->assertClean($config, 'config');

        // ─── boards
        $boards = $this->getJson($base.'/boards')->assertOk();
        $this->assertClean($boards, 'boards');
        $this->assertSame($this->sorted(['slug', 'name', 'description', 'icon', 'posts_count']), $this->keys($boards->json('data.0')));

        $detail = $this->getJson($base.'/boards/'.$board->slug)->assertOk();
        $this->assertClean($detail, 'board detail');
        $this->assertSame($this->sorted(['board', 'statuses', 'tags']), $this->keys($detail->json('data')));
        $this->assertSame($this->sorted(['slug', 'name', 'description', 'icon', 'allow_submissions', 'allow_comments', 'allow_votes', 'voting_mode', 'requires_review', 'posts_count']), $this->keys($detail->json('data.board')));
        $this->assertSame($this->sorted(['slug', 'name', 'color', 'kind', 'is_roadmap_column', 'is_default', 'locks_voting', 'posts_count']), $this->keys($detail->json('data.statuses.0')));
        $this->assertSame($this->sorted(['slug', 'name', 'color', 'posts_count']), $this->keys($detail->json('data.tags.0')));

        // ─── post list
        $list = $this->getJson($base.'/boards/'.$board->slug.'/posts')->assertOk();
        $this->assertClean($list, 'post list');
        $listKeys = $this->sorted(['number', 'slug', 'title', 'excerpt', 'author', 'status', 'tags', 'votes_count', 'comments_count', 'is_pinned', 'has_response', 'published_at', 'url_path']);
        $this->assertSame($listKeys, $this->keys($list->json('data.0')));
        $this->assertSame($this->sorted(['name', 'is_team']), $this->keys($list->json('data.0.author')));
        $this->assertSame($this->sorted(['slug', 'name', 'color', 'kind']), $this->keys($list->json('data.0.status')));
        $this->assertSame($this->sorted(['slug', 'name', 'color']), $this->keys($list->json('data.0.tags.0')));
        $this->assertSame($this->sorted(['current_page', 'total', 'per_page', 'last_page', 'from', 'to']), $this->keys($list->json('pagination')));
        $this->assertArrayNotHasKey('id', $list->json('data.0'));

        // ─── similar
        $similar = $this->getJson($base.'/boards/'.$board->slug.'/posts/similar?q=loaded')->assertOk();
        $this->assertClean($similar, 'similar');
        $this->assertSame($this->sorted(['number', 'slug', 'title', 'votes_count', 'status', 'url_path']), $this->keys($similar->json('data.0')));
        $this->assertSame($this->sorted(['slug', 'name', 'color']), $this->keys($similar->json('data.0.status')));

        // ─── detail (anonymous and as the author)
        foreach ([null, $author['token']] as $token) {
            $req = $token ? $this->withVisitor($token) : $this;
            $detailResponse = $req->getJson($base.'/boards/'.$board->slug.'/posts/'.$post->number)->assertOk();
            $this->flushHeaders();
            $this->assertClean($detailResponse, 'post detail');
            $this->assertSame(
                $this->sorted(array_merge($listKeys, ['body', 'response', 'status_history', 'merged_into', 'related_changelog', 'board', 'canonical_slug', 'viewer'])),
                $this->keys($detailResponse->json('data'))
            );
            $this->assertSame($this->sorted(['body_html', 'responded_at', 'by']), $this->keys($detailResponse->json('data.response')));
            $this->assertSame($this->sorted(['from', 'to', 'note', 'at']), $this->keys($detailResponse->json('data.status_history.0')));
            $this->assertSame($this->sorted(['slug', 'name', 'color']), $this->keys($detailResponse->json('data.status_history.0.to')));
            $this->assertSame($this->sorted(['slug', 'title', 'label', 'published_at']), $this->keys($detailResponse->json('data.related_changelog.0')));
            $this->assertSame($this->sorted(['slug', 'name']), $this->keys($detailResponse->json('data.board')));
            $this->assertSame($this->sorted(['is_owner_pending', 'moderation_state']), $this->keys($detailResponse->json('data.viewer')));
            $this->assertArrayNotHasKey('id', $detailResponse->json('data'));

            // private staff note is hidden but the transition is public
            $this->assertNull($detailResponse->json('data.status_history.1.note'));
            $this->assertSame('Public note', $detailResponse->json('data.status_history.0.note'));
            $this->assertSame('PNE Team', $detailResponse->json('data.response.by'));
        }

        // ─── comments
        $comments = $this->getJson($base.'/boards/'.$board->slug.'/posts/'.$post->number.'/comments')->assertOk();
        $this->assertClean($comments, 'comments');
        $this->assertSame($this->sorted(['id', 'parent_id', 'author', 'body', 'created_at', 'replies']), $this->keys($comments->json('data.0')));
        $this->assertSame($this->sorted(['id', 'parent_id', 'author', 'body', 'created_at', 'replies']), $this->keys($comments->json('data.0.replies.0')));
        $reply = $comments->json('data.0.replies.0');
        $this->assertSame(['name' => 'PNE Team', 'is_team' => true], $reply['author']);   // never the admin's own name
        $this->assertSame('Sam', $comments->json('data.0.author.name'));
        $this->assertFalse($comments->json('data.0.author.is_team'));

        // ─── roadmap
        $roadmap = $this->getJson($base.'/boards/'.$board->slug.'/roadmap')->assertOk();
        $this->assertClean($roadmap, 'roadmap');
        $this->assertSame($this->sorted(['columns', 'generated_at']), $this->keys($roadmap->json('data')));
        $this->assertSame($this->sorted(['status', 'total', 'posts']), $this->keys($roadmap->json('data.columns.0')));
        $withPost = collect($roadmap->json('data.columns'))->first(fn ($c) => $c['posts'] !== []);
        $this->assertSame($listKeys, $this->keys($withPost['posts'][0]));

        // ─── changelog
        $changelog = $this->getJson($base.'/changelog')->assertOk();
        $this->assertClean($changelog, 'changelog list');
        $this->assertSame($this->sorted(['slug', 'title', 'summary', 'label', 'published_at', 'board', 'url_path']), $this->keys($changelog->json('data.0')));
        $this->assertSame($this->sorted(['slug', 'name']), $this->keys($changelog->json('data.0.board')));

        $entry = $this->getJson($base.'/changelog/shipped')->assertOk();
        $this->assertClean($entry, 'changelog detail');
        $this->assertSame($this->sorted(['slug', 'title', 'summary', 'label', 'published_at', 'board', 'url_path', 'body_html', 'related_posts']), $this->keys($entry->json('data')));
        $this->assertSame($this->sorted(['board_slug', 'number', 'slug', 'title', 'url_path']), $this->keys($entry->json('data.related_posts.0')));

        // ─── me/state
        $me = $this->withVisitor($author['token'])->getJson($base.'/me/state?board='.$board->slug)->assertOk();
        $this->assertClean($me, 'me state');
        $this->assertSame($this->sorted(['voted_post_numbers', 'own_pending']), $this->keys($me->json('data')));
    }

    public function test_write_responses_have_exact_keys_and_leak_nothing(): void
    {
        $board = $this->makeBoard(['require_post_approval' => false, 'require_comment_approval' => false]);
        $post = $this->approvedPost($board);
        ['token' => $token, 'visitor' => $visitor] = $this->issueVisitor();
        $this->secrets = ['visitor id' => $visitor->id, 'token hash' => $visitor->token_hash];

        $submitted = $this->submitPost($token, $board)->assertCreated();
        $this->assertClean($submitted, 'post store');
        $this->assertSame($this->sorted(['number', 'slug', 'moderation_state', 'url_path']), $this->keys($submitted->json('data')));

        $comment = $this->submitComment($token, $board, $post)->assertCreated();
        $this->assertClean($comment, 'comment store');
        $this->assertSame($this->sorted(['id', 'moderation_state']), $this->keys($comment->json('data')));

        $vote = $this->withVisitor($token)->postJson(self::API.'/boards/'.$board->slug.'/posts/'.$post->number.'/vote', ['voted' => true])->assertOk();
        $this->assertClean($vote, 'vote');
        $this->assertSame($this->sorted(['voted', 'votes_count']), $this->keys($vote->json('data')));

        $form = $this->withVisitor($token)->postJson(self::API.'/forms/post/start', ['board' => $board->slug])->assertOk();
        $this->assertClean($form, 'form start');
        $this->assertSame($this->sorted(['form_token', 'min_seconds', 'max_age_seconds']), $this->keys($form->json('data')));

        $this->flushHeaders();
        $issued = $this->postJson(self::API.'/visitor')->assertCreated();
        $this->assertClean($issued, 'visitor issue');
        $this->assertSame($this->sorted(['visitor_token', 'is_new']), $this->keys($issued->json('data')));
        $this->assertStringStartsWith('rmv_', $issued->json('data.visitor_token'));
    }

    public function test_error_responses_leak_nothing(): void
    {
        $board = $this->makeBoard();
        $hidden = $this->makePost($board, ['moderation_state' => 'pending']);
        ['token' => $token, 'visitor' => $visitor] = $this->issueVisitor();
        $this->secrets = ['visitor id' => $visitor->id, 'token hash' => $visitor->token_hash, 'roadmap_posts' => 'roadmap_posts', 'SQLSTATE' => 'SQLSTATE'];

        $this->assertClean($this->getJson(self::API.'/boards/'.$board->slug.'/posts/'.$hidden->number), '404 hidden');
        $this->assertClean($this->getJson(self::API.'/boards/nope'), '404 board');
        $this->assertClean($this->postJson(self::API.'/boards/'.$board->slug.'/posts', []), '401');
        $this->assertClean($this->withVisitor($token)->postJson(self::API.'/boards/'.$board->slug.'/posts', []), '422');
        $this->assertClean($this->withVisitor($token)->postJson(self::API.'/boards/'.$board->slug.'/posts/'.$hidden->number.'/vote', ['voted' => true]), 'vote 404');
    }
}
