<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Heuristics that surface possible vote manipulation. Read-only and advisory: the result
 * feeds the analytics "suspicious" panel and the `suspicious` flag on visitors. Cached 60 s.
 *
 *   vote_spike          post: >= 10 votes in the last hour and >= half of all its votes
 *   concentrated_ips    post: 3 IP hashes supplied >= 80% of its latest 20 votes (>= 10 votes)
 *   new_visitor_cluster post: >= 8 votes in 24 h from visitors younger than 24 h (>= 70% of them)
 *   ip_mints_visitors   ip hash: first seen on >= 5 new visitors in the last 24 h
 *   vote_burst          visitor: >= 20 votes within 10 minutes
 */
class SuspicionService
{
    public const SPIKE_MIN = 10;

    public const MINT_MIN = 5;

    public const BURST_MIN = 20;

    public const CLUSTER_MIN = 8;

    private const TTL = 60;

    /**
     * @return array<int, array{type:string,reason_code:string,subject:string,ref:string,score:int}>
     */
    public function items(?int $boardId = null, bool $fresh = false): array
    {
        $key = 'roadmap:suspicion:'.($boardId ?? 'all');
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TTL, fn () => $this->compute($boardId));
    }

    /**
     * Ids of visitors and ip hashes that currently look suspicious (for the visitor list flag).
     *
     * @return array{visitors: array<string,true>, ips: array<string,true>}
     */
    public function flagged(): array
    {
        $visitors = [];
        $ips = [];
        foreach ($this->items(null) as $item) {
            if ($item['type'] === 'visitor') {
                $visitors[$item['ref']] = true;
            } elseif ($item['type'] === 'ip_hash') {
                $ips[$item['ref']] = true;
            }
        }

        return ['visitors' => $visitors, 'ips' => $ips];
    }

    public function isSuspicious(Visitor $visitor, ?array $flagged = null): bool
    {
        $flagged ??= $this->flagged();

        return isset($flagged['visitors'][$visitor->id])
            || ($visitor->first_ip_hash && isset($flagged['ips'][$visitor->first_ip_hash]))
            || ($visitor->last_ip_hash && isset($flagged['ips'][$visitor->last_ip_hash]));
    }

    // ─── Heuristics ──────────────────────────────────────────────────

    /** @return array<int, array{type:string,reason_code:string,subject:string,ref:string,score:int}> */
    private function compute(?int $boardId): array
    {
        $items = [
            ...$this->voteSpikes($boardId),
            ...$this->concentratedIps($boardId),
            ...$this->newVisitorClusters($boardId),
            ...$this->ipsMintingVisitors(),
            ...$this->voteBursts(),
        ];

        usort($items, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($items, 0, 25);
    }

    private function votesQuery(?int $boardId)
    {
        return DB::table('roadmap_votes as v')
            ->join('roadmap_posts as p', 'p.id', '=', 'v.post_id')
            ->when($boardId, fn ($q) => $q->where('p.board_id', $boardId));
    }

    private function voteSpikes(?int $boardId): array
    {
        $rows = $this->votesQuery($boardId)
            ->where('v.created_at', '>=', now()->subHour())
            ->selectRaw('v.post_id, COUNT(*) as recent')
            ->groupBy('v.post_id')
            ->havingRaw('COUNT(*) >= ?', [self::SPIKE_MIN])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $total = (int) DB::table('roadmap_votes')->where('post_id', $row->post_id)->count();
            if ($total > 0 && $row->recent * 2 >= $total) {
                $out[] = $this->postItem((int) $row->post_id, 'vote_spike', (int) $row->recent);
            }
        }

        return $out;
    }

    private function concentratedIps(?int $boardId): array
    {
        $candidates = $this->votesQuery($boardId)
            ->where('v.created_at', '>=', now()->subDays(7))
            ->selectRaw('v.post_id, COUNT(*) as c')
            ->groupBy('v.post_id')
            ->havingRaw('COUNT(*) >= 10')
            ->orderByDesc('c')
            ->limit(30)
            ->pluck('post_id');

        $out = [];
        foreach ($candidates as $postId) {
            $latest = DB::table('roadmap_votes')
                ->where('post_id', $postId)
                ->whereNotNull('ip_hash')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(20)
                ->pluck('ip_hash')
                ->all();

            if (count($latest) < 10) {
                continue;
            }
            $counts = array_count_values($latest);
            arsort($counts);
            $topThree = array_sum(array_slice($counts, 0, 3));

            // 3 or fewer distinct IPs behind 80% of the latest votes.
            if ($topThree / count($latest) >= 0.8 && count($counts) <= max(3, (int) floor(count($latest) / 2))) {
                $out[] = $this->postItem((int) $postId, 'concentrated_ips', (int) round($topThree / count($latest) * 100));
            }
        }

        return $out;
    }

    private function newVisitorClusters(?int $boardId): array
    {
        $rows = $this->votesQuery($boardId)
            ->join('roadmap_visitors as vis', 'vis.id', '=', 'v.visitor_id')
            ->where('v.created_at', '>=', now()->subDay())
            ->where('vis.first_seen_at', '>=', now()->subDay())
            ->selectRaw('v.post_id, COUNT(*) as fresh')
            ->groupBy('v.post_id')
            ->havingRaw('COUNT(*) >= ?', [self::CLUSTER_MIN])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $window = (int) DB::table('roadmap_votes')->where('post_id', $row->post_id)->where('created_at', '>=', now()->subDay())->count();
            if ($window > 0 && $row->fresh / $window >= 0.7) {
                $out[] = $this->postItem((int) $row->post_id, 'new_visitor_cluster', (int) $row->fresh);
            }
        }

        return $out;
    }

    private function ipsMintingVisitors(): array
    {
        $rows = DB::table('roadmap_visitors')
            ->whereNotNull('first_ip_hash')
            ->where('first_seen_at', '>=', now()->subDay())
            ->selectRaw('first_ip_hash, COUNT(*) as c')
            ->groupBy('first_ip_hash')
            ->havingRaw('COUNT(*) >= ?', [self::MINT_MIN])
            ->orderByDesc('c')
            ->limit(10)
            ->get();

        return $rows->map(fn ($r) => [
            'type' => 'ip_hash',
            'reason_code' => 'ip_mints_visitors',
            'subject' => substr((string) $r->first_ip_hash, 0, 8),
            'ref' => (string) $r->first_ip_hash,
            'score' => (int) $r->c,
        ])->all();
    }

    private function voteBursts(): array
    {
        $since = now()->subHours(6);
        $candidates = DB::table('roadmap_votes')
            ->where('created_at', '>=', $since)
            ->selectRaw('visitor_id, COUNT(*) as c')
            ->groupBy('visitor_id')
            ->havingRaw('COUNT(*) >= ?', [self::BURST_MIN])
            ->pluck('visitor_id');

        $out = [];
        foreach ($candidates as $visitorId) {
            $times = DB::table('roadmap_votes')->where('visitor_id', $visitorId)->where('created_at', '>=', $since)
                ->orderBy('created_at')->pluck('created_at')->map(fn ($t) => strtotime((string) $t))->all();

            $best = 0;
            $left = 0;
            foreach ($times as $right => $t) {
                while ($t - $times[$left] > 600) {
                    $left++;
                }
                $best = max($best, $right - $left + 1);
            }

            if ($best >= self::BURST_MIN) {
                $out[] = [
                    'type' => 'visitor',
                    'reason_code' => 'vote_burst',
                    'subject' => (string) $visitorId,
                    'ref' => (string) $visitorId,
                    'score' => $best,
                ];
            }
        }

        return $out;
    }

    private function postItem(int $postId, string $reason, int $score): array
    {
        $title = (string) Post::query()->whereKey($postId)->value('title');

        return [
            'type' => 'post',
            'reason_code' => $reason,
            'subject' => $title,
            'ref' => (string) $postId,
            'score' => $score,
        ];
    }
}
