<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Post;
use App\Models\Roadmap\Status;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Numbers for the admin overview (AnalyticsSummary). Cached 60 s per (board, days). */
class AnalyticsService
{
    public function __construct(private SuspicionService $suspicion) {}

    /** @return array<string, mixed> */
    public function summary(?int $boardId = null, int $days = 30, bool $fresh = false): array
    {
        $days = max(1, min(365, $days));
        $key = 'roadmap:analytics:'.($boardId ?? 'all').':'.$days;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 60, fn () => $this->compute($boardId, $days));
    }

    /** @return array<string, mixed> */
    private function compute(?int $boardId, int $days): array
    {
        $since = Carbon::today()->subDays($days - 1);

        $visible = fn () => Post::query()->publiclyVisible()->when($boardId, fn ($q) => $q->where('board_id', $boardId));

        $visibleVotes = fn () => DB::table('roadmap_votes as v')
            ->join('roadmap_posts as p', 'p.id', '=', 'v.post_id')
            ->where('p.moderation_state', 'approved')
            ->whereNull('p.merged_into_post_id')
            ->when($boardId, fn ($q) => $q->where('p.board_id', $boardId));

        $visibleComments = fn () => DB::table('roadmap_comments as c')
            ->join('roadmap_posts as p', 'p.id', '=', 'c.post_id')
            ->where('c.moderation_state', 'approved')
            ->when($boardId, fn ($q) => $q->where('p.board_id', $boardId));

        $topPosts = $visible()->with('status')->orderByDesc('votes_count')->orderByDesc('id')->limit(10)->get()
            ->load('board:id,slug');

        $byStatus = $visible()->selectRaw('status_id, COUNT(*) as c')->groupBy('status_id')->pluck('c', 'status_id');
        $statuses = Status::query()->whereIn('id', $byStatus->keys())->orderBy('sort_order')->get();

        return [
            'totals' => [
                'posts' => $visible()->count(),
                'votes' => $visibleVotes()->count(),
                'comments' => $visibleComments()->count(),
                'visitors' => (int) DB::table('roadmap_visitors')->count(),
            ],
            'moderation_backlog' => [
                'posts' => Post::query()->where('moderation_state', 'pending')->when($boardId, fn ($q) => $q->where('board_id', $boardId))->count(),
                'comments' => (int) DB::table('roadmap_comments as c')
                    ->join('roadmap_posts as p', 'p.id', '=', 'c.post_id')
                    ->where('c.moderation_state', 'pending')
                    ->when($boardId, fn ($q) => $q->where('p.board_id', $boardId))
                    ->count(),
            ],
            'votes_per_day' => $this->perDay(
                $visibleVotes()->where('v.created_at', '>=', $since)->selectRaw('DATE(v.created_at) as d, COUNT(*) as c')->groupByRaw('DATE(v.created_at)')->pluck('c', 'd'),
                $since,
                $days
            ),
            'posts_per_day' => $this->perDay(
                $visible()->where('created_at', '>=', $since)->selectRaw('DATE(created_at) as d, COUNT(*) as c')->groupByRaw('DATE(created_at)')->pluck('c', 'd'),
                $since,
                $days
            ),
            'top_posts' => $topPosts->map(fn (Post $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'board_slug' => $p->board?->slug,
                'title' => $p->title,
                'votes_count' => $p->votes_count,
                'status' => $this->statusRef($p->status),
            ])->values()->all(),
            'by_status' => $statuses->map(fn (Status $s) => [
                'status' => $this->statusRef($s),
                'count' => (int) $byStatus[$s->id],
            ])->values()->all(),
            'suspicious' => $this->suspicion->items($boardId),
        ];
    }

    /** @param Collection<string,int> $counts */
    private function perDay($counts, Carbon $since, int $days): array
    {
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }

        return $out;
    }

    private function statusRef(?Status $s): array
    {
        return [
            'id' => $s?->id,
            'slug' => $s?->slug,
            'name' => $s?->name,
            'color' => $s?->color,
            'kind' => $s?->kind,
        ];
    }
}
