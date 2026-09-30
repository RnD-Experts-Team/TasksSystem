<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Setting;
use App\Models\Roadmap\Status;
use App\Models\Roadmap\Visitor;
use App\Models\User;
use App\Services\Roadmap\FormTokenService;
use App\Support\Roadmap\RuntimeSettings;
use App\Support\Roadmap\TextSanitizer;
use Database\Seeders\RoadmapPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Shared fixture for the Roadmap feature tests.
 *
 * Time note: form tokens have a minimum age (settings.moderation.min_*_seconds). Tests that
 * submit content either use {@see submitPost()} / {@see submitComment()} (they advance the
 * clock past the minimum with Carbon::setTestNow) or call startForm() and advance time
 * themselves.
 */
abstract class RoadmapTestCase extends TestCase
{
    use RefreshDatabase;

    protected const API = '/api/public/roadmap';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'roadmap.hash_key' => str_repeat('a', 64),
            'roadmap.frontend_url' => 'https://app.test',
            'broadcasting.default' => 'null',
        ]);

        Cache::flush();

        $this->seed(RoadmapPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ─── Boards / posts ──────────────────────────────────────────

    /**
     * A board with the default status set: Under review (default), Planned, In progress,
     * Live (the three roadmap columns), Not planned (closed, locks voting).
     *
     * Board DB defaults apply (posts + comments require approval). Override via $overrides.
     */
    protected function makeBoard(array $overrides = []): Board
    {
        $board = Board::create(array_merge([
            'slug' => 'board-'.Str::lower(Str::random(6)),
            'name' => 'Test Board',
            'description' => 'A board for tests',
        ], $overrides));

        $defaults = [
            ['Under review', 'under-review', '#64748b', 'open', false, true, false],
            ['Planned', 'planned', '#6366f1', 'planned', true, false, false],
            ['In progress', 'in-progress', '#f59e0b', 'in_progress', true, false, false],
            ['Live', 'live', '#10b981', 'done', true, false, false],
            ['Not planned', 'not-planned', '#ef4444', 'closed', false, false, true],
        ];

        foreach ($defaults as $i => [$name, $slug, $color, $kind, $column, $default, $locks]) {
            Status::create([
                'board_id' => $board->id,
                'name' => $name,
                'slug' => $slug,
                'color' => $color,
                'kind' => $kind,
                'sort_order' => $i,
                'is_roadmap_column' => $column,
                'is_default' => $default,
                'locks_voting' => $locks,
            ]);
        }

        return $board->fresh();
    }

    protected function statusOf(Board $board, string $slug): Status
    {
        return Status::query()->where('board_id', $board->id)->where('slug', $slug)->firstOrFail();
    }

    /** An approved, publicly visible post (numbers are allocated from the board counter). */
    protected function approvedPost(Board $board, array $overrides = []): Post
    {
        return $this->makePost($board, array_merge(['moderation_state' => 'approved'], $overrides));
    }

    /** Any post in any moderation state. */
    protected function makePost(Board $board, array $overrides = []): Post
    {
        $board = $board->fresh();
        $number = $overrides['number'] ?? $board->next_post_number;
        $board->update(['next_post_number' => max($board->next_post_number, $number + 1)]);

        $title = $overrides['title'] ?? 'Sample idea number '.$number;
        $state = $overrides['moderation_state'] ?? 'approved';
        $statusId = $overrides['status_id'] ?? Status::query()->where('board_id', $board->id)->where('is_default', true)->value('id');

        $attributes = array_merge([
            'board_id' => $board->id,
            'number' => $number,
            'slug' => TextSanitizer::slug($title),
            'title' => $title,
            'body' => 'Body text for '.$title,
            'author_name' => null,
            'moderation_state' => $state,
            'published_at' => $state === 'approved' ? now() : null,
            'status_id' => $statusId,
            'content_hash' => TextSanitizer::contentHash($title, 'Body text for '.$title),
            'last_activity_at' => now(),
        ], $overrides);

        return Post::create($attributes)->fresh();
    }

    /** Persist (part of) the global settings, e.g. setSettings(['limits' => ['votes_per_visitor_day' => 3]]). */
    protected function setSettings(array $data): void
    {
        $stored = Setting::query()->where('scope', 'global')->value('data') ?? [];
        Setting::updateOrCreate(['scope' => 'global'], ['data' => array_replace_recursive($stored, $data)]);
        RuntimeSettings::flush();
    }

    // ─── Visitors ────────────────────────────────────────────────

    /**
     * Creates a visitor row directly (no HTTP, no throttling).
     *
     * Helper visitors are 3 days old by default so the "new visitor" vote cap does not apply;
     * pass ['first_seen_at' => now()] to get a brand new visitor.
     *
     * @return array{token: string, visitor: Visitor}
     */
    protected function issueVisitor(array $attributes = []): array
    {
        $token = 'rmv_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $visitor = Visitor::create(array_merge([
            'id' => (string) Str::ulid(),
            'token_hash' => hash('sha256', $token),
            'first_ip_hash' => str_repeat('1', 32),
            'last_ip_hash' => str_repeat('1', 32),
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now(),
        ], $attributes));

        return ['token' => $token, 'visitor' => $visitor->fresh()];
    }

    /** Send the visitor token on the following request(s). */
    protected function withVisitor(string $token): static
    {
        return $this->withHeader('X-Visitor-Token', $token);
    }

    // ─── Staff ───────────────────────────────────────────────────

    /** A user holding ONLY the "admin" role (no direct permissions). */
    protected function admin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole('admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A staff user with direct permissions (guard sanctum) and no role. */
    protected function staff(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    // ─── Form tokens & submissions ───────────────────────────────

    /** A signed form token for the visitor that owns $token (issued "now"). */
    protected function startForm(string $token, Board $board, string $kind = 'post'): string
    {
        $visitor = Visitor::query()->where('token_hash', hash('sha256', $token))->firstOrFail();

        return app(FormTokenService::class)->issue($kind, $board, $visitor)['form_token'];
    }

    /** Advance the fake clock beyond the minimum submit time. */
    protected function passMinTime(int $seconds = 30): void
    {
        Carbon::setTestNow(now()->addSeconds($seconds));
    }

    /** Submit a post through the HTTP API (form token issued and clock advanced automatically). */
    protected function submitPost(string $token, Board $board, array $payload = [], bool $wait = true)
    {
        $formToken = $payload['form_token'] ?? $this->startForm($token, $board, 'post');
        if ($wait) {
            $this->passMinTime();
        }

        return $this->withVisitor($token)->postJson(self::API.'/boards/'.$board->slug.'/posts', array_merge([
            'title' => 'A perfectly reasonable idea',
            'body' => 'It would help us a lot if this existed.',
            'form_token' => $formToken,
        ], $payload));
    }

    protected function submitComment(string $token, Board $board, Post $post, array $payload = [], bool $wait = true)
    {
        $formToken = $payload['form_token'] ?? $this->startForm($token, $board, 'comment');
        if ($wait) {
            $this->passMinTime();
        }

        return $this->withVisitor($token)->postJson(
            self::API.'/boards/'.$board->slug.'/posts/'.$post->number.'/comments',
            array_merge(['body' => 'I agree, this would be great.', 'form_token' => $formToken], $payload)
        );
    }
}
