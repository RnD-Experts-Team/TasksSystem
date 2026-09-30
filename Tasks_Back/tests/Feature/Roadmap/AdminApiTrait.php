<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Visitor;
use App\Models\Roadmap\Vote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** Small helpers shared by the admin-side roadmap tests. */
trait AdminApiTrait
{
    protected const ADMIN_API = '/api/roadmap/admin';

    protected function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    /** Authenticate as an admin (role only, no direct permissions). */
    protected function asAdmin(): User
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    protected function vote(int $postId, string $visitorId, ?string $ipHash = null, $at = null): Vote
    {
        return Vote::create([
            'post_id' => $postId,
            'visitor_id' => $visitorId,
            'ip_hash' => $ipHash,
            'created_at' => $at ?? now(),
        ]);
    }

    protected function comment(int $postId, ?string $visitorId, array $overrides = []): Comment
    {
        return Comment::create(array_merge([
            'post_id' => $postId,
            'visitor_id' => $visitorId,
            'author_name' => null,
            'body' => 'A comment',
            'moderation_state' => 'approved',
        ], $overrides));
    }

    /** @return Visitor a visitor row (not via HTTP) */
    protected function visitor(array $attributes = []): Visitor
    {
        return $this->issueVisitor($attributes)['visitor'];
    }

    protected function boardWithStatuses(array $overrides = []): Board
    {
        return $this->makeBoard($overrides);
    }
}
