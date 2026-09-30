<?php

namespace App\Services\Roadmap;

use App\Jobs\Roadmap\PruneRoadmapDataJob;
use App\Models\Roadmap\Board;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use App\Models\Roadmap\Vote;
use App\Support\Roadmap\IpHasher;
use App\Support\Roadmap\Limits;
use App\Support\Roadmap\RoadmapLimitException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Anonymous visitor identity. The raw token (`rmv_…`) is shown once to the browser;
 * only its SHA-256 is stored.
 */
class VisitorService
{
    public const TOKEN_PREFIX = 'rmv_';

    /**
     * Issue a new token, or confirm the one the client already holds.
     *
     * @return array{visitor_token: string, is_new: bool}
     *
     * @throws RoadmapLimitException when the client IP minted too many tokens today
     */
    public function issue(Request $request): array
    {
        $existing = $request->attributes->get('roadmap.visitor');
        $sent = trim((string) $request->headers->get('X-Visitor-Token', ''));
        if ($existing instanceof Visitor && $sent !== '') {
            return ['visitor_token' => $sent, 'is_new' => false];
        }

        $ip = IpHasher::forRequest($request);

        $mintedToday = Visitor::query()
            ->where('first_ip_hash', $ip)
            ->where('first_seen_at', '>=', now()->subDay())
            ->count();
        if ($mintedToday >= Limits::cap('tokens_per_ip_day')) {
            throw new RoadmapLimitException('daily_limit', 'Too many new visitors from this network today. Please try again tomorrow.', 3600);
        }

        $token = self::TOKEN_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        Visitor::create([
            'id' => (string) Str::ulid(),
            'token_hash' => hash('sha256', $token),
            'first_ip_hash' => $ip,
            'last_ip_hash' => $ip,
            'ua_hash' => IpHasher::uaHash($request->userAgent()),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->maybePrune();

        return ['visitor_token' => $token, 'is_new' => true];
    }

    /**
     * State for the current visitor (never cached). An unknown visitor gets empty arrays.
     *
     * @return array{voted_post_numbers: array<int,int>, own_pending: array<int,array<string,mixed>>}
     */
    public function state(?Visitor $visitor, ?Board $board): array
    {
        if (! $visitor) {
            return ['voted_post_numbers' => [], 'own_pending' => []];
        }

        $voted = [];
        if ($board) {
            $voted = Vote::query()
                ->join('roadmap_posts', 'roadmap_posts.id', '=', 'roadmap_votes.post_id')
                ->where('roadmap_votes.visitor_id', $visitor->id)
                ->where('roadmap_posts.board_id', $board->id)
                ->where('roadmap_posts.moderation_state', 'approved')
                ->whereNull('roadmap_posts.merged_into_post_id')
                ->orderBy('roadmap_posts.number')
                ->pluck('roadmap_posts.number')
                ->map(fn ($n) => (int) $n)
                ->all();
        }

        $pending = [];

        $posts = Post::query()->where('visitor_id', $visitor->id)
            ->whereIn('moderation_state', ['pending', 'spam'])   // spam looks pending to its author
            ->when($board, fn ($q) => $q->where('board_id', $board->id))
            ->orderByDesc('id')->limit(50)->get(['number', 'title', 'created_at']);
        foreach ($posts as $post) {
            $pending[] = [
                'type' => 'post',
                'number' => (int) $post->number,
                'title' => $post->title,
                'created_at' => $post->created_at?->toIso8601ZuluString(),
            ];
        }

        $comments = Comment::query()->where('visitor_id', $visitor->id)
            ->whereIn('moderation_state', ['pending', 'spam'])
            ->whereHas('post', fn ($q) => $q->when($board, fn ($q2) => $q2->where('board_id', $board->id)))
            ->with('post:id,number,title')
            ->orderByDesc('id')->limit(50)->get();
        foreach ($comments as $comment) {
            $pending[] = [
                'type' => 'comment',
                'number' => $comment->post ? (int) $comment->post->number : null,
                'title' => $comment->post?->title,
                'created_at' => $comment->created_at?->toIso8601ZuluString(),
            ];
        }

        return ['voted_post_numbers' => $voted, 'own_pending' => $pending];
    }

    /** No scheduler container exists in production: piggy-back on the rare "new visitor" call. */
    private function maybePrune(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (random_int(1, 500) === 1) {
            PruneRoadmapDataJob::dispatch();
        }
    }
}
