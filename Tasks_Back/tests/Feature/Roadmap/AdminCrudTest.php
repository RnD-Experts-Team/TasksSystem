<?php

namespace Tests\Feature\Roadmap;

use App\Events\Roadmap\ChangelogPublished;
use App\Events\Roadmap\CommentApproved;
use App\Events\Roadmap\PostApproved;
use App\Events\Roadmap\PostStatusChanged;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Setting;
use App\Models\Roadmap\Status;
use App\Models\Roadmap\StatusChange;
use App\Models\Roadmap\Tag;
use App\Models\Roadmap\Visitor;
use App\Services\Roadmap\BoardAdminService;
use App\Services\Roadmap\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

class AdminCrudTest extends RoadmapTestCase
{
    use AdminApiTrait;

    private function api(string $path = ''): string
    {
        return self::ADMIN_API.$path;
    }

    // ─── Boards ──────────────────────────────────────────────────────

    public function test_creating_a_board_seeds_the_default_statuses(): void
    {
        $this->asAdmin();

        $res = $this->postJson($this->api('/boards'), ['name' => 'Web App', 'description' => 'The web app'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'web-app')
            ->assertJsonPath('data.require_post_approval', true)
            ->assertJsonPath('data.trust_after_approved', 3)
            ->assertJsonPath('data.posts_count', 0);

        $statuses = collect($res->json('data.statuses'));
        $this->assertSame(['Under review', 'Planned', 'In progress', 'Live', 'Not planned'], $statuses->pluck('name')->all());
        $this->assertSame(['open', 'planned', 'in_progress', 'done', 'closed'], $statuses->pluck('kind')->all());
        $this->assertSame(['#64748b', '#6366f1', '#f59e0b', '#10b981', '#ef4444'], $statuses->pluck('color')->all());
        $this->assertSame([false, true, true, true, false], $statuses->pluck('is_roadmap_column')->all());
        $this->assertSame([true, false, false, false, false], $statuses->pluck('is_default')->all());
        $this->assertSame([false, false, false, false, true], $statuses->pluck('locks_voting')->all());

        $this->assertSame(5, Status::where('board_id', $res->json('data.id'))->count());
    }

    public function test_board_slugs_are_generated_deduplicated_and_reserved_words_rejected(): void
    {
        $this->asAdmin();

        $this->postJson($this->api('/boards'), ['name' => 'Mobile'])->assertCreated()->assertJsonPath('data.slug', 'mobile');
        $this->postJson($this->api('/boards'), ['name' => 'Mobile'])->assertCreated()->assertJsonPath('data.slug', 'mobile-2');
        // A name that slugifies to a reserved word is suffixed instead of failing.
        $this->postJson($this->api('/boards'), ['name' => 'Roadmap'])->assertCreated()->assertJsonPath('data.slug', 'roadmap-board');
        // An explicit reserved / malformed / taken slug is a validation error.
        $this->postJson($this->api('/boards'), ['name' => 'X', 'slug' => 'admin'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson($this->api('/boards'), ['name' => 'X', 'slug' => 'Bad Slug'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson($this->api('/boards'), ['name' => 'X', 'slug' => 'mobile'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson($this->api('/boards'), [])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_changing_a_board_slug_writes_a_redirect(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard(['slug' => 'old-name']);

        $this->patchJson($this->api("/boards/{$board->id}"), ['slug' => 'new-name', 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'new-name')
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertDatabaseHas('roadmap_slug_redirects', ['kind' => 'board', 'old_key' => 'old-name', 'target_id' => $board->id]);

        // Renaming back reclaims the slug and drops the stale redirect.
        $this->patchJson($this->api("/boards/{$board->id}"), ['slug' => 'old-name'])->assertOk();
        $this->assertDatabaseMissing('roadmap_slug_redirects', ['kind' => 'board', 'old_key' => 'old-name']);
        $this->assertDatabaseHas('roadmap_slug_redirects', ['kind' => 'board', 'old_key' => 'new-name']);
    }

    public function test_board_show_index_update_flags_and_reorder(): void
    {
        $this->asAdmin();
        $a = $this->makeBoard(['slug' => 'a', 'sort_order' => 0]);
        $b = $this->makeBoard(['slug' => 'b', 'sort_order' => 1]);
        $this->approvedPost($a);
        $this->makePost($a, ['moderation_state' => 'pending']);

        $index = $this->getJson($this->api('/boards'))->assertOk();
        $row = collect($index->json('data'))->firstWhere('slug', 'a');
        $this->assertSame(1, $row['posts_count']);
        $this->assertSame(1, $row['pending_count']);
        $this->assertArrayNotHasKey('statuses', $row);

        $this->getJson($this->api("/boards/{$a->id}"))->assertOk()
            ->assertJsonCount(5, 'data.statuses')
            ->assertJsonStructure(['data' => ['tags', 'statuses' => [['id', 'posts_count', 'is_default']]]]);

        $this->patchJson($this->api("/boards/{$a->id}"), ['allow_votes' => false, 'require_post_approval' => false, 'is_archived' => true, 'trust_after_approved' => 5])
            ->assertOk()->assertJsonPath('data.allow_votes', false)->assertJsonPath('data.is_archived', true)->assertJsonPath('data.trust_after_approved', 5);

        $this->postJson($this->api('/boards/reorder'), ['ids' => [$b->id, $a->id]])->assertOk();
        $this->assertSame(0, $b->fresh()->sort_order);
        $this->assertSame(1, $a->fresh()->sort_order);

        $this->getJson($this->api('/boards/9999'))->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_a_board_with_posts_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $this->asAdmin();
        $full = $this->makeBoard();
        $this->approvedPost($full);
        $empty = $this->makeBoard();

        $this->deleteJson($this->api("/boards/{$full->id}"))
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'board_not_empty');
        $this->assertDatabaseHas('roadmap_boards', ['id' => $full->id]);

        $this->deleteJson($this->api("/boards/{$empty->id}"))->assertOk();
        $this->assertDatabaseMissing('roadmap_boards', ['id' => $empty->id]);
        $this->assertSame(0, Status::where('board_id', $empty->id)->count());
    }

    // ─── Statuses ────────────────────────────────────────────────────

    public function test_status_create_update_reorder_and_make_default(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();

        $created = $this->postJson($this->api("/boards/{$board->id}/statuses"), [
            'name' => 'Beta', 'color' => '#AABBCC', 'kind' => 'in_progress', 'is_roadmap_column' => true,
        ])->assertCreated()->assertJsonPath('data.slug', 'beta')->assertJsonPath('data.color', '#aabbcc')->assertJsonPath('data.is_default', false);
        $id = $created->json('data.id');

        $this->postJson($this->api("/boards/{$board->id}/statuses"), ['name' => 'Beta', 'color' => 'red', 'kind' => 'open'])
            ->assertStatus(422)->assertJsonValidationErrors('color');
        $this->postJson($this->api("/boards/{$board->id}/statuses"), ['name' => 'X', 'color' => '#112233', 'kind' => 'weird'])
            ->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->postJson($this->api("/boards/{$board->id}/statuses"), ['name' => 'X', 'slug' => 'beta', 'color' => '#112233', 'kind' => 'open'])
            ->assertStatus(422)->assertJsonValidationErrors('slug');

        $this->patchJson($this->api("/statuses/$id"), ['name' => 'Beta 2', 'locks_voting' => true])
            ->assertOk()->assertJsonPath('data.name', 'Beta 2')->assertJsonPath('data.locks_voting', true);

        $this->postJson($this->api("/boards/{$board->id}/statuses/$id/make-default"))->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertSame(1, Status::where('board_id', $board->id)->where('is_default', true)->count());
        $this->assertTrue(Status::find($id)->is_default);

        $all = Status::where('board_id', $board->id)->orderBy('id')->pluck('id')->reverse()->values()->all();
        $this->postJson($this->api("/boards/{$board->id}/statuses/reorder"), ['ids' => $all])->assertOk();
        $this->assertSame($all, Status::where('board_id', $board->id)->orderBy('sort_order')->pluck('id')->all());

        $this->getJson($this->api("/boards/{$board->id}/statuses"))->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_deleting_a_used_status_requires_a_reassignment_and_moves_the_posts(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $planned = $this->statusOf($board, 'planned');
        $inProgress = $this->statusOf($board, 'in-progress');
        $post = $this->approvedPost($board, ['status_id' => $planned->id]);
        StatusChange::create(['post_id' => $post->id, 'from_status_id' => null, 'to_status_id' => $planned->id, 'created_at' => now()]);

        $this->deleteJson($this->api("/statuses/{$planned->id}"))
            ->assertStatus(400)->assertJsonPath('code', 'reassign_required');

        // Status of another board is not a valid target.
        $other = $this->makeBoard();
        $this->deleteJson($this->api("/statuses/{$planned->id}?reassign_to_status_id=".$this->statusOf($other, 'planned')->id))
            ->assertStatus(400)->assertJsonPath('code', 'invalid_reassign');

        $this->deleteJson($this->api("/statuses/{$planned->id}?reassign_to_status_id={$inProgress->id}"))->assertOk();

        $this->assertDatabaseMissing('roadmap_statuses', ['id' => $planned->id]);
        $this->assertSame($inProgress->id, $post->fresh()->status_id);
        $this->assertDatabaseHas('roadmap_status_changes', ['post_id' => $post->id, 'to_status_id' => $inProgress->id]);
    }

    public function test_an_unused_status_deletes_without_reassignment_and_the_default_never_does(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();

        $this->deleteJson($this->api('/statuses/'.$this->statusOf($board, 'under-review')->id))
            ->assertStatus(400)->assertJsonPath('code', 'default_status');

        $this->deleteJson($this->api('/statuses/'.$this->statusOf($board, 'not-planned')->id))->assertOk();
        $this->assertNull(Status::where('board_id', $board->id)->where('slug', 'not-planned')->first());
    }

    // ─── Tags ────────────────────────────────────────────────────────

    public function test_tag_crud_and_reorder(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();

        $a = $this->postJson($this->api("/boards/{$board->id}/tags"), ['name' => 'Bug'])->assertCreated()
            ->assertJsonPath('data.slug', 'bug')->assertJsonPath('data.color', '#64748b')->json('data');
        $b = $this->postJson($this->api("/boards/{$board->id}/tags"), ['name' => 'UX', 'color' => '#FF0000'])->assertCreated()
            ->assertJsonPath('data.color', '#ff0000')->json('data');
        $this->postJson($this->api("/boards/{$board->id}/tags"), ['name' => 'Bug'])->assertCreated()->assertJsonPath('data.slug', 'bug-2');
        $this->postJson($this->api("/boards/{$board->id}/tags"), ['name' => 'Z', 'color' => 'nope'])->assertStatus(422);

        $this->patchJson($this->api("/tags/{$a['id']}"), ['name' => 'Defect', 'color' => '#00ff00'])
            ->assertOk()->assertJsonPath('data.name', 'Defect')->assertJsonPath('data.color', '#00ff00');

        $post = $this->approvedPost($board);
        $post->tags()->attach($a['id']);
        $this->getJson($this->api("/boards/{$board->id}/tags"))->assertOk()
            ->assertJsonPath('data.0.posts_count', 1);

        $this->postJson($this->api("/boards/{$board->id}/tags/reorder"), ['ids' => [$b['id'], $a['id']]])->assertOk();
        $this->assertSame(0, Tag::find($b['id'])->sort_order);

        $this->deleteJson($this->api("/tags/{$a['id']}"))->assertOk();
        $this->assertDatabaseMissing('roadmap_post_tag', ['tag_id' => $a['id']]);
    }

    // ─── Posts ───────────────────────────────────────────────────────

    public function test_admin_can_create_edit_and_delete_a_post(): void
    {
        Event::fake([PostApproved::class]);
        $admin = $this->asAdmin();
        $board = $this->makeBoard();
        $tag = Tag::create(['board_id' => $board->id, 'name' => 'Bug', 'slug' => 'bug']);

        $res = $this->postJson($this->api('/posts'), [
            'board_id' => $board->id, 'title' => '  Dark   mode please  ', 'body' => "Line one\n\n\n\nLine two", 'tag_ids' => [$tag->id],
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Dark mode please')
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.moderation_state', 'approved')
            ->assertJsonPath('data.created_by_admin', true)
            ->assertJsonPath('data.visitor', null)
            ->assertJsonPath('data.status.slug', 'under-review')
            ->assertJsonPath('data.tags.0.slug', 'bug')
            ->assertJsonPath('data.public_url_path', '/roadmap/'.$board->slug.'/p/1-dark-mode-please');
        $id = $res->json('data.id');

        Event::assertDispatched(PostApproved::class);
        $this->assertSame(2, $board->fresh()->next_post_number);
        $this->assertSame($admin->id, Post::find($id)->created_by_user_id);

        $second = $this->postJson($this->api('/posts'), ['board_id' => $board->id, 'title' => 'Second one'])->assertCreated();
        $this->assertSame(2, $second->json('data.number'));

        $this->patchJson($this->api("/posts/$id"), ['title' => 'Dark mode', 'body' => 'Updated', 'author_name' => 'Sam'])
            ->assertOk()->assertJsonPath('data.title', 'Dark mode')->assertJsonPath('data.slug', 'dark-mode')->assertJsonPath('data.author_name', 'Sam');

        $this->postJson($this->api('/posts'), ['board_id' => $board->id, 'title' => 'x'])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->postJson($this->api('/posts'), ['board_id' => 9999, 'title' => 'A valid title'])->assertStatus(422)->assertJsonValidationErrors('board_id');

        $this->deleteJson($this->api("/posts/$id"))->assertOk();
        $this->assertDatabaseMissing('roadmap_posts', ['id' => $id]);
        $this->getJson($this->api("/posts/$id"))->assertNotFound();
    }

    public function test_post_list_filters_pagination_and_visitor_reference(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $visitor = $this->visitor(['first_ip_hash' => 'abcdef0123456789abcdef0123456789', 'last_ip_hash' => 'abcdef0123456789abcdef0123456789']);
        $a = $this->approvedPost($board, ['title' => 'Alpha search target', 'votes_count' => 5, 'visitor_id' => $visitor->id, 'ip_hash' => 'abcdef0123456789abcdef0123456789', 'flags' => ['links:3']]);
        $b = $this->approvedPost($board, ['title' => 'Beta thing', 'votes_count' => 9]);
        $c = $this->makePost($board, ['title' => 'Gamma pending', 'moderation_state' => 'pending']);

        $all = $this->getJson($this->api('/posts?per_page=2'))->assertOk()
            ->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.per_page', 2)->assertJsonPath('pagination.last_page', 2)
            ->assertJsonCount(2, 'data');
        $this->assertSame($c->id, $all->json('data.0.id'), 'default sort is newest first');

        $this->getJson($this->api('/posts?moderation_state=pending'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $c->id);
        $this->getJson($this->api('/posts?q=search'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson($this->api('/posts?sort=votes&moderation_state=approved'))->assertJsonPath('data.0.id', $b->id);
        $this->getJson($this->api('/posts?visitor_id='.$visitor->id))->assertJsonCount(1, 'data');
        $this->getJson($this->api('/posts?ip_hash=abcdef0123456789abcdef0123456789'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('/posts?per_page=500'))->assertStatus(422);

        $row = collect($this->getJson($this->api('/posts?q=Alpha'))->json('data'))->first();
        $this->assertSame('abcdef01', $row['visitor']['ip_hash_short']);
        $this->assertSame(['links:3'], $row['flags']);
        $this->assertArrayNotHasKey('token_hash', $row['visitor']);
        $this->assertStringNotContainsString('abcdef0123456789abcdef0123456789', json_encode($row));
        $this->assertSame($board->slug, $row['board']['slug']);
        $this->assertStringEndsWith('Z', $row['created_at']);
    }

    public function test_status_change_writes_history_fires_the_event_and_validates(): void
    {
        Event::fake([PostStatusChanged::class]);
        $admin = $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        $planned = $this->statusOf($board, 'planned');

        $res = $this->postJson($this->api("/posts/{$post->id}/status"), ['status_id' => $planned->id, 'note' => 'Next sprint', 'note_is_public' => true])
            ->assertOk()
            ->assertJsonPath('data.status.slug', 'planned')
            ->assertJsonPath('data.status_history.0.to.slug', 'planned')
            ->assertJsonPath('data.status_history.0.from.slug', 'under-review')
            ->assertJsonPath('data.status_history.0.note', 'Next sprint')
            ->assertJsonPath('data.status_history.0.changed_by_name', $admin->name);
        $this->assertNotNull($res->json('data.last_activity_at'));

        $change = StatusChange::where('post_id', $post->id)->firstOrFail();
        $this->assertSame($admin->id, $change->changed_by);
        $this->assertTrue($change->is_public);
        Event::assertDispatched(PostStatusChanged::class, fn ($e) => $e->post->id === $post->id && $e->toStatusId === $planned->id);

        // Private note.
        $done = $this->statusOf($board, 'live');
        $this->postJson($this->api("/posts/{$post->id}/status"), ['status_id' => $done->id, 'note' => 'internal', 'note_is_public' => false])->assertOk();
        $this->assertFalse(StatusChange::where('post_id', $post->id)->latest('id')->first()->is_public);

        // Same status, other board's status, missing status, long note.
        $this->postJson($this->api("/posts/{$post->id}/status"), ['status_id' => $done->id])->assertStatus(400)->assertJsonPath('code', 'status_unchanged');
        $foreign = $this->statusOf($this->makeBoard(), 'planned');
        $this->postJson($this->api("/posts/{$post->id}/status"), ['status_id' => $foreign->id])->assertStatus(400)->assertJsonPath('code', 'invalid_status');
        $this->postJson($this->api("/posts/{$post->id}/status"), [])->assertStatus(422);
        $this->postJson($this->api("/posts/{$post->id}/status"), ['status_id' => $planned->id, 'note' => str_repeat('n', 501)])->assertStatus(422);

        $this->assertSame(2, StatusChange::where('post_id', $post->id)->count());
    }

    public function test_moderating_a_post_publishes_it_and_updates_the_visitor(): void
    {
        Event::fake([PostApproved::class]);
        $admin = $this->asAdmin();
        $board = $this->makeBoard(['trust_after_approved' => 1]);
        $visitor = $this->visitor();
        $post = $this->makePost($board, ['moderation_state' => 'pending', 'visitor_id' => $visitor->id]);
        Visitor::whereKey($visitor->id)->update(['posts_count' => 1]);

        $this->postJson($this->api("/posts/{$post->id}/moderate"), ['state' => 'approved'])
            ->assertOk()->assertJsonPath('data.moderation_state', 'approved');

        $post->refresh();
        $this->assertNotNull($post->published_at);
        $this->assertNotNull($post->moderated_at);
        $this->assertSame($admin->id, $post->moderated_by);
        $visitor->refresh();
        $this->assertSame(1, $visitor->approved_posts_count);
        $this->assertTrue($visitor->is_trusted, 'threshold of 1 approved item reached');
        Event::assertDispatchedTimes(PostApproved::class, 1);

        // Approving again does not re-fire the event; rejecting takes the approved count away.
        $this->postJson($this->api("/posts/{$post->id}/moderate"), ['state' => 'approved'])->assertOk();
        Event::assertDispatchedTimes(PostApproved::class, 1);
        $this->postJson($this->api("/posts/{$post->id}/moderate"), ['state' => 'rejected', 'reason' => 'Off topic'])
            ->assertOk()->assertJsonPath('data.moderation_reason', 'Off topic');
        $this->assertSame(0, $visitor->fresh()->approved_posts_count);

        $this->postJson($this->api("/posts/{$post->id}/moderate"), ['state' => 'bogus'])->assertStatus(422);
    }

    public function test_bulk_moderate_posts(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $posts = collect(range(1, 3))->map(fn () => $this->makePost($board, ['moderation_state' => 'pending']));

        $this->postJson($this->api('/posts/bulk-moderate'), ['ids' => $posts->pluck('id')->all(), 'state' => 'spam'])
            ->assertOk()->assertJsonPath('data.updated', 3);
        $this->assertSame(3, Post::where('moderation_state', 'spam')->count());

        $this->postJson($this->api('/posts/bulk-moderate'), ['ids' => range(1, 101), 'state' => 'spam'])->assertStatus(422);
        $this->postJson($this->api('/posts/bulk-moderate'), ['ids' => [], 'state' => 'spam'])->assertStatus(422);
    }

    public function test_official_response_can_be_set_and_cleared(): void
    {
        $admin = $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);

        $this->putJson($this->api("/posts/{$post->id}/response"), ['body_md' => "We **love** it.\n\n[More](https://example.com)"])
            ->assertOk()
            ->assertJsonPath('data.response_md', "We **love** it.\n\n[More](https://example.com)")
            ->assertJsonPath('data.responded_at', fn ($v) => $v !== null);

        $post->refresh();
        $this->assertStringContainsString('<strong>love</strong>', $post->response_html);
        $this->assertSame($admin->id, $post->responded_by);

        $this->putJson($this->api("/posts/{$post->id}/response"), [])->assertStatus(422);
        $this->putJson($this->api("/posts/{$post->id}/response"), ['body_md' => str_repeat('a', 10001)])->assertStatus(422);

        $this->deleteJson($this->api("/posts/{$post->id}/response"))->assertOk()->assertJsonPath('data.response_md', null);
        $post->refresh();
        $this->assertNull($post->response_html);
        $this->assertNull($post->responded_at);
    }

    public function test_pin_toggle_and_explicit_state(): void
    {
        $this->asAdmin();
        $post = $this->approvedPost($this->makeBoard());

        $this->postJson($this->api("/posts/{$post->id}/pin"))->assertOk()->assertJsonPath('data.is_pinned', true);
        $this->postJson($this->api("/posts/{$post->id}/pin"))->assertOk()->assertJsonPath('data.is_pinned', false);
        $this->postJson($this->api("/posts/{$post->id}/pin"), ['pinned' => true])->assertOk()->assertJsonPath('data.is_pinned', true);
        $this->postJson($this->api("/posts/{$post->id}/pin"), ['pinned' => true])->assertOk()->assertJsonPath('data.is_pinned', true);
    }

    public function test_sync_tags_only_accepts_tags_of_the_posts_board(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        $t1 = Tag::create(['board_id' => $board->id, 'name' => 'One', 'slug' => 'one']);
        $t2 = Tag::create(['board_id' => $board->id, 'name' => 'Two', 'slug' => 'two']);
        $foreign = Tag::create(['board_id' => $this->makeBoard()->id, 'name' => 'Nope', 'slug' => 'nope']);

        $this->putJson($this->api("/posts/{$post->id}/tags"), ['tag_ids' => [$t1->id, $t2->id]])
            ->assertOk()->assertJsonCount(2, 'data.tags');
        $this->putJson($this->api("/posts/{$post->id}/tags"), ['tag_ids' => [$t2->id]])->assertOk()->assertJsonCount(1, 'data.tags');
        $this->putJson($this->api("/posts/{$post->id}/tags"), ['tag_ids' => [$t1->id, $foreign->id]])
            ->assertStatus(400)->assertJsonPath('code', 'invalid_tags');
        $this->putJson($this->api("/posts/{$post->id}/tags"), ['tag_ids' => []])->assertOk()->assertJsonCount(0, 'data.tags');
    }

    public function test_moving_a_post_reassigns_its_number_writes_a_redirect_and_remaps_tags(): void
    {
        $this->asAdmin();
        $from = $this->makeBoard(['slug' => 'from']);
        $to = $this->makeBoard(['slug' => 'to']);
        $this->approvedPost($to); // number 1 on the target board
        $post = $this->approvedPost($from);
        $keep = Tag::create(['board_id' => $from->id, 'name' => 'Bug', 'slug' => 'bug']);
        $drop = Tag::create(['board_id' => $from->id, 'name' => 'Only here', 'slug' => 'only-here']);
        $post->tags()->attach([$keep->id, $drop->id]);
        $targetBug = Tag::create(['board_id' => $to->id, 'name' => 'bug', 'slug' => 'bug']);
        $targetPlanned = $this->statusOf($to, 'planned');

        $this->postJson($this->api("/posts/{$post->id}/move"), ['board_id' => $to->id, 'status_id' => $targetPlanned->id])
            ->assertOk()
            ->assertJsonPath('data.board.slug', 'to')
            ->assertJsonPath('data.number', 2)
            ->assertJsonPath('data.status.slug', 'planned')
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $targetBug->id);

        $this->assertDatabaseHas('roadmap_slug_redirects', ['kind' => 'post', 'old_key' => $from->id.':'.$post->number, 'target_id' => $post->id]);
        $this->assertSame(3, $to->fresh()->next_post_number);

        // Same board / foreign status are rejected.
        $this->postJson($this->api("/posts/{$post->id}/move"), ['board_id' => $to->id])->assertStatus(400)->assertJsonPath('code', 'same_board');
        $this->postJson($this->api("/posts/{$post->id}/move"), ['board_id' => $from->id, 'status_id' => $targetPlanned->id])
            ->assertStatus(400)->assertJsonPath('code', 'invalid_status');

        // Moving back falls back to the default status of the board.
        $this->postJson($this->api("/posts/{$post->id}/move"), ['board_id' => $from->id])
            ->assertOk()->assertJsonPath('data.status.slug', 'under-review')->assertJsonPath('data.number', 2);
    }

    public function test_post_detail_exposes_history_votes_by_ip_and_merged_from(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board, ['body' => 'Full body text']);
        $dup = $this->approvedPost($board, ['merged_into_post_id' => $post->id, 'title' => 'Duplicate']);
        foreach ([1, 2, 3] as $i) {
            $v = $this->visitor();
            $this->vote($post->id, $v->id, 'aaaaaaaa'.str_repeat('0', 24));
        }
        $this->vote($post->id, $this->visitor()->id, 'bbbbbbbb'.str_repeat('0', 24));

        $data = $this->getJson($this->api("/posts/{$post->id}"))->assertOk()->json('data');

        $this->assertSame('Full body text', $data['body']);
        $this->assertSame([['ip_hash_short' => 'aaaaaaaa', 'count' => 3], ['ip_hash_short' => 'bbbbbbbb', 'count' => 1]], $data['votes_by_ip']);
        $this->assertSame([['id' => $dup->id, 'number' => $dup->number, 'title' => 'Duplicate']], $data['merged_from']);
        $this->assertSame([], $data['status_history']);
        $this->assertArrayHasKey('response_html', $data);
    }

    // ─── Comments ────────────────────────────────────────────────────

    public function test_comment_moderation_reply_and_delete_keep_counts_right(): void
    {
        Event::fake([CommentApproved::class]);
        $admin = $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        $visitor = $this->visitor();
        $pending = $this->comment($post->id, $visitor->id, ['moderation_state' => 'pending', 'author_name' => 'Vic']);
        $this->comment($post->id, $visitor->id, ['moderation_state' => 'approved']);

        $list = $this->getJson($this->api('/comments?moderation_state=pending'))->assertOk()->assertJsonPath('pagination.total', 1);
        $this->assertSame($pending->id, $list->json('data.0.id'));
        $this->assertSame($post->number, $list->json('data.0.post.number'));
        $this->assertSame($board->slug, $list->json('data.0.post.board_slug'));
        $this->assertSame($visitor->id, $list->json('data.0.visitor.id'));

        $this->postJson($this->api("/comments/{$pending->id}/moderate"), ['state' => 'approved'])->assertOk()->assertJsonPath('data.moderation_state', 'approved');
        $this->assertSame(2, $post->fresh()->comments_count);
        $this->assertSame(2, $visitor->fresh()->approved_comments_count);
        Event::assertDispatched(CommentApproved::class);

        $this->postJson($this->api("/comments/{$pending->id}/moderate"), ['state' => 'spam'])->assertOk();
        $this->assertSame(1, $post->fresh()->comments_count);

        $reply = $this->postJson($this->api("/posts/{$post->id}/comments"), ['body' => "Thanks\nfor the idea"])
            ->assertCreated()->assertJsonPath('data.is_admin', true)->assertJsonPath('data.moderation_state', 'approved')->json('data');
        $this->assertSame(2, $post->fresh()->comments_count);
        $this->assertSame($admin->id, Comment::find($reply['id'])->user_id);

        // Nested reply must target a top-level comment of the same post.
        $this->postJson($this->api("/posts/{$post->id}/comments"), ['body' => 'nested', 'parent_id' => $reply['id']])->assertCreated();
        $this->postJson($this->api("/posts/{$post->id}/comments"), ['body' => 'too deep', 'parent_id' => Comment::latest('id')->first()->id])
            ->assertStatus(400)->assertJsonPath('code', 'invalid_parent');
        $this->postJson($this->api("/posts/{$post->id}/comments"), ['body' => ''])->assertStatus(422);

        $this->postJson($this->api('/comments/bulk-moderate'), ['ids' => [$pending->id], 'state' => 'approved'])->assertOk()->assertJsonPath('data.updated', 1);

        $this->deleteJson($this->api("/comments/{$reply['id']}"))->assertOk();
        $this->assertDatabaseMissing('roadmap_comments', ['id' => $reply['id']]);
        $this->assertSame($post->fresh()->comments_count, Comment::where('post_id', $post->id)->where('moderation_state', 'approved')->count());
    }

    // ─── Visitors ────────────────────────────────────────────────────

    public function test_visitor_list_detail_ban_and_unban_never_leak_tokens_or_raw_ips(): void
    {
        $admin = $this->asAdmin();
        $board = $this->makeBoard();
        $v = $this->issueVisitor(['last_ip_hash' => 'c0ffee00c0ffee00c0ffee00c0ffee00']);
        $visitor = $v['visitor'];
        $post = $this->approvedPost($board, ['visitor_id' => $visitor->id]);
        $this->vote($post->id, $visitor->id, 'c0ffee00c0ffee00c0ffee00c0ffee00');
        $this->comment($post->id, $visitor->id);
        $this->visitor(['last_ip_hash' => 'c0ffee00c0ffee00c0ffee00c0ffee00']);

        $list = $this->getJson($this->api('/visitors?q=c0ffee'))->assertOk();
        $this->assertSame(2, $list->json('pagination.total'));
        $row = collect($list->json('data'))->firstWhere('id', $visitor->id);
        $this->assertSame('c0ffee00', $row['ip_hash_short']);
        $this->assertSame('c0ffee00c0ffee00c0ffee00c0ffee00', $row['ip_hash']);
        $this->assertFalse($row['suspicious']);
        $this->assertStringNotContainsString($v['token'], $list->getContent());
        $this->assertStringNotContainsString(hash('sha256', $v['token']), $list->getContent());

        $detail = $this->getJson($this->api("/visitors/{$visitor->id}"))->assertOk()->json('data');
        $this->assertSame(1, $detail['same_ip_visitors']);
        $this->assertSame($post->title, $detail['recent_posts'][0]['title']);
        $this->assertSame($board->slug, $detail['recent_posts'][0]['board_slug']);
        $this->assertSame($post->title, $detail['recent_votes'][0]['post_title']);
        $this->assertCount(1, $detail['recent_comments']);

        $this->postJson($this->api("/visitors/{$visitor->id}/ban"), ['reason' => 'Spammer'])
            ->assertOk()->assertJsonPath('data.is_banned', true)->assertJsonPath('data.banned_reason', 'Spammer');
        $fresh = $visitor->fresh();
        $this->assertTrue($fresh->is_banned);
        $this->assertSame($admin->id, $fresh->banned_by);
        $this->assertNotNull($fresh->banned_at);

        $this->postJson($this->api("/visitors/{$visitor->id}/unban"))->assertOk()->assertJsonPath('data.is_banned', false);
        $this->assertNull($visitor->fresh()->banned_reason);

        $this->getJson($this->api('/visitors/01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertNotFound();
        $this->getJson($this->api('/visitors?banned=1'))->assertJsonPath('pagination.total', 0);
    }

    // ─── Changelog ───────────────────────────────────────────────────

    public function test_changelog_lifecycle_and_scheduling(): void
    {
        Event::fake([ChangelogPublished::class]);
        $this->asAdmin();
        $board = $this->makeBoard();

        $created = $this->postJson($this->api('/changelog'), [
            'title' => 'Big release', 'label' => 'new', 'summary' => 'Short', 'body_md' => '# Hello', 'board_id' => $board->id,
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'big-release')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_scheduled', false)
            ->assertJsonPath('data.board.slug', $board->slug)
            ->assertJsonPath('data.body_html', '<h1>Hello</h1>');
        $id = $created->json('data.id');

        $this->assertSame(0, ChangelogEntry::live()->count(), 'drafts are not live');

        // Schedule: published with a future date stays hidden from the public scope.
        $future = now()->addDays(3)->toIso8601String();
        $this->postJson($this->api("/changelog/$id/publish"), ['published_at' => $future])
            ->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.is_scheduled', true);
        $this->assertSame(0, ChangelogEntry::live()->count());
        Event::assertNotDispatched(ChangelogPublished::class);

        // Publish now.
        $this->postJson($this->api("/changelog/$id/publish"), ['published_at' => now()->subMinute()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.is_scheduled', false);
        $this->assertSame(1, ChangelogEntry::live()->count());
        Event::assertDispatched(ChangelogPublished::class);

        $this->postJson($this->api("/changelog/$id/unpublish"))->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertSame(0, ChangelogEntry::live()->count());

        // Publish without a date -> now.
        $res = $this->postJson($this->api("/changelog/$id/publish"))->assertOk()->assertJsonPath('data.is_scheduled', false);
        $this->assertNotNull($res->json('data.published_at'));

        // Update: body re-rendered; slug change writes a redirect.
        $this->patchJson($this->api("/changelog/$id"), ['title' => 'Bigger release', 'slug' => 'bigger-release', 'body_md' => '**bold**', 'label' => 'improved'])
            ->assertOk()->assertJsonPath('data.slug', 'bigger-release')->assertJsonPath('data.label', 'improved')->assertJsonPath('data.body_html', '<p><strong>bold</strong></p>');
        $this->assertDatabaseHas('roadmap_slug_redirects', ['kind' => 'changelog', 'old_key' => 'big-release', 'target_id' => $id]);

        // Filters + list.
        $this->postJson($this->api('/changelog'), ['title' => 'Draft one', 'label' => 'fixed', 'body_md' => 'x']);
        $this->getJson($this->api('/changelog?status=draft'))->assertJsonPath('pagination.total', 1);
        $this->getJson($this->api('/changelog?status=published'))->assertJsonPath('pagination.total', 1);
        $this->getJson($this->api('/changelog'))->assertJsonPath('pagination.total', 2);

        // Validation.
        $this->postJson($this->api('/changelog'), ['title' => 'T', 'label' => 'big', 'body_md' => 'x'])->assertStatus(422)->assertJsonValidationErrors('label');
        $this->postJson($this->api('/changelog'), ['title' => 'T', 'label' => 'new'])->assertStatus(422)->assertJsonValidationErrors('body_md');
        $this->postJson($this->api('/changelog'), ['title' => 'T', 'label' => 'new', 'body_md' => 'x', 'slug' => 'bigger-release'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson($this->api('/changelog'), ['title' => 'T', 'label' => 'new', 'body_md' => 'x', 'summary' => str_repeat('s', 281)])->assertStatus(422);

        $this->deleteJson($this->api("/changelog/$id"))->assertOk();
        $this->assertDatabaseMissing('roadmap_changelog_entries', ['id' => $id]);
        $this->assertDatabaseMissing('roadmap_slug_redirects', ['kind' => 'changelog', 'target_id' => $id]);
        $this->getJson($this->api("/changelog/$id"))->assertNotFound();
    }

    public function test_a_future_date_kept_on_a_draft_is_used_when_publishing_without_a_date(): void
    {
        $this->asAdmin();
        $future = now()->addDays(5)->toIso8601String();
        $id = $this->postJson($this->api('/changelog'), ['title' => 'Later', 'label' => 'new', 'body_md' => 'x', 'published_at' => $future])
            ->assertCreated()->json('data.id');

        $this->postJson($this->api("/changelog/$id/publish"))->assertOk()->assertJsonPath('data.is_scheduled', true);
        $this->assertSame(0, ChangelogEntry::live()->count());
    }

    public function test_changelog_sync_posts_can_mark_them_as_shipped(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);
        $other = $this->approvedPost($this->makeBoard());
        $live = $this->statusOf($board, 'live');
        $entry = ChangelogEntry::create(['title' => 'E', 'slug' => 'e', 'label' => 'new', 'body_md' => 'x', 'body_html' => '<p>x</p>', 'status' => 'draft']);

        $res = $this->putJson($this->api("/changelog/{$entry->id}/posts"), ['post_ids' => [$post->id, $other->id], 'mark_status_id' => $live->id])
            ->assertOk()->assertJsonPath('data.linked_posts_count', 2);
        $this->assertCount(2, $res->json('data.linked_posts'));

        $this->assertSame($live->id, $post->fresh()->status_id, 'post of the status board moves');
        $this->assertNotSame($live->id, $other->fresh()->status_id, 'post of another board is only linked');
        $this->assertDatabaseHas('roadmap_status_changes', ['post_id' => $post->id, 'to_status_id' => $live->id]);

        $this->putJson($this->api("/changelog/{$entry->id}/posts"), ['post_ids' => []])->assertOk()->assertJsonPath('data.linked_posts_count', 0);
        $this->putJson($this->api("/changelog/{$entry->id}/posts"), ['post_ids' => [1], 'mark_status_id' => 9999])->assertStatus(422);
    }

    // ─── Roadmap kanban ──────────────────────────────────────────────

    public function test_roadmap_kanban_data_and_move(): void
    {
        Event::fake([PostStatusChanged::class]);
        $this->asAdmin();
        $board = $this->makeBoard();
        $planned = $this->statusOf($board, 'planned');
        $progress = $this->statusOf($board, 'in-progress');
        $a = $this->approvedPost($board, ['status_id' => $planned->id, 'roadmap_order' => 0, 'votes_count' => 1]);
        $b = $this->approvedPost($board, ['status_id' => $planned->id, 'roadmap_order' => 1, 'votes_count' => 9]);
        $c = $this->approvedPost($board, ['status_id' => $progress->id]);
        $this->makePost($board, ['status_id' => $planned->id, 'moderation_state' => 'pending']); // never on the board
        $this->approvedPost($board, ['status_id' => $this->statusOf($board, 'not-planned')->id]);   // not a roadmap column

        $data = $this->getJson($this->api("/boards/{$board->id}/roadmap"))->assertOk()->json('data');
        $this->assertSame($board->slug, $data['board']['slug']);
        $this->assertSame(['planned', 'in-progress', 'live'], array_column(array_column($data['columns'], 'status'), 'slug'));
        $this->assertSame([$a->id, $b->id], array_column($data['columns'][0]['posts'], 'id'));
        $this->assertSame([$c->id], array_column($data['columns'][1]['posts'], 'id'));
        $this->assertEqualsCanonicalizing(['id', 'number', 'title', 'votes_count', 'comments_count', 'is_pinned', 'tags', 'roadmap_order'], array_keys($data['columns'][0]['posts'][0]));

        // Reorder inside a column (no status change).
        $this->postJson($this->api("/boards/{$board->id}/roadmap/move"), ['post_id' => $b->id, 'to_status_id' => $planned->id, 'ordered_ids' => [$b->id, $a->id]])
            ->assertOk()->assertJsonPath('data.columns.0.posts.0.id', $b->id);
        $this->assertSame(0, $b->fresh()->roadmap_order);
        $this->assertSame(1, $a->fresh()->roadmap_order);
        Event::assertNotDispatched(PostStatusChanged::class);
        $this->assertSame(0, StatusChange::count());

        // Move across columns: status changes, history written, destination ordered.
        $this->postJson($this->api("/boards/{$board->id}/roadmap/move"), ['post_id' => $a->id, 'to_status_id' => $progress->id, 'ordered_ids' => [$c->id, $a->id]])
            ->assertOk()->assertJsonPath('data.columns.1.posts.1.id', $a->id);
        $this->assertSame($progress->id, $a->fresh()->status_id);
        $this->assertEquals([$c->id => 0, $a->id => 1], Post::whereIn('id', [$a->id, $c->id])->pluck('roadmap_order', 'id')->all());
        $this->assertDatabaseHas('roadmap_status_changes', ['post_id' => $a->id, 'from_status_id' => $planned->id, 'to_status_id' => $progress->id]);
        Event::assertDispatched(PostStatusChanged::class);

        // Wrong board / status.
        $other = $this->makeBoard();
        $this->postJson($this->api("/boards/{$other->id}/roadmap/move"), ['post_id' => $a->id, 'to_status_id' => $this->statusOf($other, 'planned')->id, 'ordered_ids' => []])->assertNotFound();
        $this->postJson($this->api("/boards/{$board->id}/roadmap/move"), ['post_id' => $a->id, 'to_status_id' => $this->statusOf($other, 'planned')->id, 'ordered_ids' => []])
            ->assertStatus(400)->assertJsonPath('code', 'invalid_status');
        $this->getJson($this->api('/boards/9999/roadmap'))->assertNotFound();
    }

    // ─── Settings ────────────────────────────────────────────────────

    public function test_settings_show_defaults_and_save_partial_updates(): void
    {
        $this->asAdmin();

        $data = $this->getJson($this->api('/settings'))->assertOk()->assertJsonPath('data.scope', 'global')->json('data');
        $this->assertSame('PNE Roadmap', $data['settings']['site']['name']);
        $this->assertSame('#e11d48', $data['settings']['branding']['primary']);
        $this->assertArrayHasKey('logo_path', $data['settings']['branding']);
        $this->assertArrayHasKey('og_image_path', $data['settings']['branding']);
        $this->assertArrayNotHasKey('logo', $data['settings']['branding']);
        $this->assertSame(['site', 'branding', 'features', 'moderation', 'limits', 'seo'], array_keys($data['settings']));
        $this->assertArrayHasKey('--primary', $data['theme']['light']);
        $this->assertSame(['logo_url' => null, 'logo_dark_url' => null, 'favicon_url' => null, 'og_image_url' => null], $data['assets']);

        $saved = $this->putJson($this->api('/settings'), ['scope' => 'global', 'data' => [
            'site' => ['name' => 'Acme Feedback', 'tagline' => '', 'footer_links' => [['label' => 'Docs', 'url' => 'https://acme.test/docs']]],
            'branding' => ['primary' => '#3B82F6', 'radius' => 'xl', 'hero_style' => 'pattern'],
            'moderation' => ['blocklist' => ['Viagra', 'viagra', ' casino '], 'max_links_post' => 1],
            'limits' => ['votes_per_ip_day' => 200],
            'features' => ['comments' => false],
            'seo' => ['indexable' => false, 'title_suffix' => '| Acme', 'meta_description' => 'Hello'],
            'evil' => ['x' => 1],
        ]])->assertOk()->json('data');

        $s = $saved['settings'];
        $this->assertSame('Acme Feedback', $s['site']['name']);
        $this->assertNull($s['site']['tagline'], 'cleared nullable text becomes null');
        $this->assertSame($s['site']['tagline'] === 'Tell us what to build next', false);
        // assertEquals: MySQL JSON columns reorder object keys, so compare values, not key order
        $this->assertEquals([['label' => 'Docs', 'url' => 'https://acme.test/docs']], $s['site']['footer_links']);
        $this->assertSame('#3b82f6', $s['branding']['primary']);
        $this->assertSame('xl', $s['branding']['radius']);
        $this->assertSame(['viagra', 'casino'], $s['moderation']['blocklist']);
        $this->assertSame(1, $s['moderation']['max_links_post']);
        $this->assertSame(200, $s['limits']['votes_per_ip_day']);
        $this->assertSame(30, $s['limits']['votes_per_visitor_day'], 'untouched keys keep their default');
        $this->assertFalse($s['features']['comments']);
        $this->assertTrue($s['features']['roadmap']);
        $this->assertFalse($s['seo']['indexable']);
        $this->assertArrayNotHasKey('evil', $s);
        $this->assertSame('1.25rem', $saved['theme']['radius']);

        // A second partial save keeps the first.
        $this->putJson($this->api('/settings'), ['scope' => 'global', 'data' => ['site' => ['team_name' => 'Acme Crew']]])->assertOk();
        $again = $this->getJson($this->api('/settings'))->json('data.settings');
        $this->assertSame('Acme Crew', $again['site']['team_name']);
        $this->assertSame('Acme Feedback', $again['site']['name']);
        $this->assertSame('#3b82f6', $again['branding']['primary']);
    }

    public function test_settings_validation(): void
    {
        $this->asAdmin();
        $put = fn (array $data, string $scope = 'global') => $this->putJson($this->api('/settings'), ['scope' => $scope, 'data' => $data]);

        $put(['branding' => ['primary' => 'red']])->assertStatus(422)->assertJsonValidationErrors('data.branding.primary');
        $put(['branding' => ['primary' => '#12345']])->assertStatus(422);
        $put(['branding' => ['radius' => 'huge']])->assertStatus(422);
        $put(['branding' => ['font' => 'comic']])->assertStatus(422);
        $put(['moderation' => ['blocklist' => array_map(fn ($i) => "w$i", range(1, 501))]])->assertStatus(422)->assertJsonValidationErrors('data.moderation.blocklist');
        $put(['moderation' => ['blocklist' => [str_repeat('x', 61)]]])->assertStatus(422);
        $put(['moderation' => ['blocklist' => array_map(fn ($i) => "w$i", range(1, 500))]])->assertOk();
        $put(['limits' => ['votes_per_visitor_day' => 0]])->assertStatus(422);
        $put(['limits' => ['votes_per_visitor_day' => 'many']])->assertStatus(422);
        $put(['features' => ['rss' => 'maybe']])->assertStatus(422);
        $put(['seo' => ['meta_description' => str_repeat('d', 161)]])->assertStatus(422);
        $put(['site' => ['footer_links' => [['label' => 'x', 'url' => 'javascript:alert(1)']]]])->assertStatus(422);
        $put(['site' => ['contact_url' => 'javascript:alert(1)']])->assertStatus(422);
        $put(['site' => ['default_board_slug' => 'missing-board']])->assertStatus(422);
        $put(['site' => ['name' => '']])->assertStatus(422);
        $this->putJson($this->api('/settings'), ['data' => ['site' => ['name' => 'x']]])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->putJson($this->api('/settings'), ['scope' => 'weird', 'data' => []])->assertStatus(422);
        $put(['site' => ['name' => 'Ok']], 'board:99999')->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->getJson($this->api('/settings?scope=board:99999'))->assertNotFound();
    }

    public function test_board_scope_may_only_override_hero_copy_and_a_few_branding_keys(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $scope = 'board:'.$board->id;

        $res = $this->putJson($this->api('/settings'), ['scope' => $scope, 'data' => [
            'site' => ['hero_title' => 'Mobile ideas', 'name' => 'Hacked', 'team_name' => 'Hacked'],
            'branding' => ['primary' => '#10b981', 'radius' => 'sm', 'hero_style' => 'plain', 'font' => 'system', 'default_theme' => 'dark'],
            'limits' => ['votes_per_visitor_day' => 1],
            'moderation' => ['blocklist' => ['x']],
        ]])->assertOk()->json('data.settings');

        $this->assertSame('Mobile ideas', $res['site']['hero_title']);
        $this->assertSame('PNE Roadmap', $res['site']['name'], 'not overridable');
        $this->assertSame('PNE Team', $res['site']['team_name']);
        $this->assertSame('#10b981', $res['branding']['primary']);
        $this->assertSame('sm', $res['branding']['radius']);
        $this->assertSame('plain', $res['branding']['hero_style']);
        $this->assertSame('outfit', $res['branding']['font'], 'not overridable');
        $this->assertSame('system', $res['branding']['default_theme']);
        $this->assertSame(30, $res['limits']['votes_per_visitor_day']);
        $this->assertSame([], $res['moderation']['blocklist']);

        $stored = Setting::where('scope', $scope)->firstOrFail()->data;
        $this->assertEqualsCanonicalizing(['site', 'branding'], array_keys($stored));
        $this->assertEqualsCanonicalizing(['hero_title'], array_keys($stored['site']));

        // The global scope is untouched.
        $this->assertSame('#e11d48', $this->getJson($this->api('/settings'))->json('data.settings.branding.primary'));
        // Deleting the board removes its override row.
        $this->deleteJson($this->api("/boards/{$board->id}"))->assertOk();
        $this->assertDatabaseMissing('roadmap_settings', ['scope' => $scope]);
    }

    public function test_settings_changes_reach_the_public_config_and_bust_the_cache(): void
    {
        $this->asAdmin();
        $this->makeBoard(['slug' => 'web', 'name' => 'Web']);
        $service = app(SettingsService::class);

        $before = $service->publicConfig();
        $this->assertSame('PNE Roadmap', $before['site']['name']);
        $this->assertSame(['site', 'theme', 'features', 'assets', 'boards', 'limits_hint', 'version'], array_keys($before));
        $this->assertSame(['slug', 'name', 'description', 'icon', 'posts_count'], array_keys($before['boards'][0]));
        $this->assertSame(['name', 'tagline', 'hero_title', 'hero_subtitle', 'hero_style', 'team_name', 'footer_text', 'footer_links', 'contact_url', 'default_board_slug'], array_keys($before['site']));
        $this->assertSame(['title_min' => 8, 'title_max' => 140, 'body_max' => 5000, 'comment_min' => 2, 'comment_max' => 2000, 'author_name_max' => 40, 'max_tags' => 3], $before['limits_hint']);

        $this->putJson($this->api('/settings'), ['scope' => 'global', 'data' => ['site' => ['name' => 'Renamed'], 'branding' => ['primary' => '#7c3aed']]])->assertOk();

        $after = $service->publicConfig();
        $this->assertSame('Renamed', $after['site']['name'], 'the 60 s cache is busted by the write');
        $this->assertNotSame($before['version'], $after['version']);
        $this->assertNotSame($before['theme']['light']['--primary'], $after['theme']['light']['--primary']);
    }

    // ─── Asset uploads ───────────────────────────────────────────────

    public function test_logo_upload_is_reencoded_renamed_and_replaces_the_previous_file(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        $res = $this->post($this->api('/settings/asset'), ['type' => 'logo', 'file' => UploadedFile::fake()->image('my logo.png', 1200, 600)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.type', 'logo');
        $path = $res->json('data.path');

        $this->assertMatchesRegularExpression('#^roadmap/[a-z0-9]{40}\.webp$#', $path);
        $this->assertStringNotContainsString('logo', basename($path), 'original name is never kept');
        Storage::disk('public')->assertExists($path);
        $this->assertNotEmpty($res->json('data.url'));

        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame('image/webp', $info['mime']);
        $this->assertLessThanOrEqual(512, max($info[0], $info[1]));
        $this->assertSame(512, $info[0], 'long side is scaled to 512');
        $this->assertSame(256, $info[1]);

        $settings = $this->getJson($this->api('/settings'))->json('data');
        $this->assertSame($path, $settings['settings']['branding']['logo_path']);
        $this->assertNotNull($settings['assets']['logo_url']);
        $this->assertSame($settings['assets']['logo_url'], app(SettingsService::class)->publicConfig()['assets']['logo_url']);

        // Replacing deletes the old file.
        $second = $this->post($this->api('/settings/asset'), ['type' => 'logo', 'file' => UploadedFile::fake()->image('b.jpg', 100, 100)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.path');
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($second);

        $this->deleteJson($this->api('/settings/asset/logo'))->assertOk();
        Storage::disk('public')->assertMissing($second);
        $this->assertNull($this->getJson($this->api('/settings'))->json('data.settings.branding.logo_path'));
    }

    public function test_favicon_and_og_asset_sizes(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        $fav = $this->post($this->api('/settings/asset'), ['type' => 'favicon', 'file' => UploadedFile::fake()->image('f.png', 300, 200)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.path');
        $info = getimagesizefromstring(Storage::disk('public')->get($fav));
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame([64, 64], [$info[0], $info[1]]);
        $this->assertStringEndsWith('.png', $fav);

        $og = $this->post($this->api('/settings/asset'), ['type' => 'og', 'file' => UploadedFile::fake()->image('o.jpg', 1800, 1200)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.path');
        $info = getimagesizefromstring(Storage::disk('public')->get($og));
        $this->assertSame('image/webp', $info['mime']);
        $this->assertLessThanOrEqual(1200, $info[0]);
        $this->assertLessThanOrEqual(630, $info[1]);
        $this->assertSame(945, $info[0], '1800x1200 fits 1200x630 at 945x630');

        $og2 = $this->post($this->api('/settings/asset'), ['type' => 'og', 'file' => UploadedFile::fake()->image('o.png', 800, 400)], ['Accept' => 'application/json'])->json('data.path');
        $info = getimagesizefromstring(Storage::disk('public')->get($og2));
        $this->assertSame([800, 400], [$info[0], $info[1]], 'images are never scaled up');
    }

    public function test_asset_upload_rejects_bad_files(): void
    {
        Storage::fake('public');
        $this->asAdmin();
        $post = fn (array $payload) => $this->post($this->api('/settings/asset'), $payload, ['Accept' => 'application/json']);

        // Oversized dimensions, favicon dimension cap, size cap.
        $post(['type' => 'logo', 'file' => UploadedFile::fake()->image('big.png', 2001, 100)])->assertStatus(422)->assertJsonValidationErrors('file');
        $post(['type' => 'favicon', 'file' => UploadedFile::fake()->image('big.png', 600, 600)])->assertStatus(422)->assertJsonValidationErrors('file');
        $post(['type' => 'logo', 'file' => UploadedFile::fake()->image('heavy.png', 100, 100)->size(2048)])->assertStatus(422);
        // Wrong type / no file / unknown type.
        $post(['type' => 'banner', 'file' => UploadedFile::fake()->image('a.png')])->assertStatus(422)->assertJsonValidationErrors('type');
        $post(['type' => 'logo'])->assertStatus(422)->assertJsonValidationErrors('file');

        // SVG, PDF, GIF and a script disguised as a PNG.
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $post(['type' => 'logo', 'file' => $svg])->assertStatus(422);
        $post(['type' => 'logo', 'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])->assertStatus(422);
        $post(['type' => 'logo', 'file' => UploadedFile::fake()->image('a.gif', 10, 10)])->assertStatus(422);
        $fake = UploadedFile::fake()->createWithContent('logo.png', "<?php echo 'pwned'; ?>");
        $post(['type' => 'logo', 'file' => $fake])->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_asset_deletion_of_unknown_type_is_a_404_and_unset_assets_are_a_noop(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        $this->deleteJson($this->api('/settings/asset/banner'))->assertNotFound();
        $this->deleteJson($this->api('/settings/asset/logo'))->assertOk();
    }

    // ─── Envelope ────────────────────────────────────────────────────

    public function test_unexpected_errors_never_echo_the_exception_message(): void
    {
        config(['app.debug' => true]);
        $this->asAdmin();
        $this->makeBoard();
        $mock = \Mockery::mock(BoardAdminService::class);
        $mock->shouldReceive('list')->andThrow(new \RuntimeException('SQLSTATE[42S02]: secret table name'));
        $this->app->instance(BoardAdminService::class, $mock);

        $res = $this->getJson($this->api('/boards'))->assertStatus(500)->assertJsonPath('success', false);

        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        $this->assertStringNotContainsString('secret', $res->getContent());
        $this->assertSame('Something went wrong.', $res->json('message'));
    }

    public function test_list_endpoints_use_the_envelope_with_pagination_block(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $this->approvedPost($board);

        foreach (['/posts', '/comments', '/visitors', '/changelog', '/abuse/events'] as $path) {
            $this->getJson($this->api($path))->assertOk()
                ->assertJsonStructure(['success', 'data', 'pagination' => ['current_page', 'total', 'per_page', 'last_page', 'from', 'to'], 'message']);
        }
    }

    public function test_analytics_summary_shape(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board, ['votes_count' => 2]);
        $this->makePost($board, ['moderation_state' => 'pending']);
        $v = $this->visitor();
        $this->vote($post->id, $v->id, str_repeat('1', 32));
        $this->comment($post->id, $v->id, ['moderation_state' => 'pending']);

        $data = $this->getJson($this->api('/analytics/summary?days=7'))->assertOk()->json('data');

        $this->assertSame(['totals', 'moderation_backlog', 'votes_per_day', 'posts_per_day', 'top_posts', 'by_status', 'suspicious'], array_keys($data));
        $this->assertSame(1, $data['totals']['posts']);
        $this->assertSame(1, $data['totals']['votes']);
        $this->assertSame(1, $data['moderation_backlog']['posts']);
        $this->assertSame(1, $data['moderation_backlog']['comments']);
        $this->assertCount(7, $data['votes_per_day']);
        $this->assertSame(now()->toDateString(), $data['votes_per_day'][6]['date']);
        $this->assertSame(1, $data['votes_per_day'][6]['count']);
        $this->assertSame($post->id, $data['top_posts'][0]['id']);
        $this->assertSame('under-review', $data['top_posts'][0]['status']['slug']);
        $this->assertSame(1, $data['by_status'][0]['count']);
        $this->getJson($this->api('/analytics/summary?days=0'))->assertStatus(422);
        $this->getJson($this->api("/analytics/summary?board_id={$board->id}"))->assertOk();
    }
}
