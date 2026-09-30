<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\BanVisitorRequest;
use App\Http\Requests\Roadmap\Admin\VisitorIndexRequest;
use App\Http\Resources\Roadmap\Admin\AdminVisitorDetailResource;
use App\Http\Resources\Roadmap\Admin\AdminVisitorResource;
use App\Models\Roadmap\Visitor;
use App\Services\Roadmap\ModerationService;
use App\Services\Roadmap\SuspicionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VisitorController extends AdminController
{
    public function __construct(
        private ModerationService $moderation,
        private SuspicionService $suspicion,
    ) {}

    public function index(VisitorIndexRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $page = $this->moderation->visitors($request->validated());
            $flagged = $this->suspicion->flagged();
            $page->getCollection()->each(fn (Visitor $v) => $v->setAttribute('suspicious', $this->suspicion->isSuspicious($v, $flagged)));

            return $this->paginated($page, AdminVisitorResource::collection($page->getCollection())->resolve(), 'Visitors retrieved successfully');
        });
    }

    public function show(string $visitor): JsonResponse
    {
        return $this->run(function () use ($visitor) {
            $model = Visitor::query()->find($visitor);

            return $model ? $this->ok($this->detail($model), 'Visitor retrieved successfully') : $this->notFound('Visitor not found');
        });
    }

    public function ban(BanVisitorRequest $request, string $visitor): JsonResponse
    {
        return $this->run(function () use ($request, $visitor) {
            $model = Visitor::query()->find($visitor);
            if (! $model) {
                return $this->notFound('Visitor not found');
            }

            $data = $request->validated();
            $removed = $this->moderation->ban(
                $model,
                $data['reason'] ?? null,
                (bool) ($data['remove_content'] ?? false),
                (bool) ($data['remove_votes'] ?? false),
                $request->user()
            );

            return $this->ok(
                $this->detail($model->fresh()) + ['removed' => [
                    'votes_removed' => $removed['votes_removed'],
                    'posts_removed' => $removed['posts_removed'],
                    'comments_removed' => $removed['comments_removed'],
                ]],
                'Visitor banned successfully'
            );
        });
    }

    public function unban(Request $request, string $visitor): JsonResponse
    {
        return $this->run(function () use ($visitor) {
            $model = Visitor::query()->find($visitor);
            if (! $model) {
                return $this->notFound('Visitor not found');
            }

            return $this->ok($this->detail($this->moderation->unban($model)), 'Visitor unbanned successfully');
        });
    }

    /** @return array<string, mixed> */
    private function detail(Visitor $visitor): array
    {
        $visitor->setAttribute('suspicious', $this->suspicion->isSuspicious($visitor));

        $visitor->setAttribute('recent_posts', DB::table('roadmap_posts as p')
            ->join('roadmap_boards as b', 'b.id', '=', 'p.board_id')
            ->where('p.visitor_id', $visitor->id)
            ->orderByDesc('p.id')->limit(10)
            ->get(['p.id', 'p.number', 'b.slug as board_slug', 'p.title', 'p.moderation_state', 'p.created_at'])
            ->map(function ($row) {
                $row->created_at = $row->created_at ? Carbon::parse($row->created_at) : null;

                return $row;
            }));

        $visitor->setAttribute('recent_comments', DB::table('roadmap_comments')
            ->where('visitor_id', $visitor->id)
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'post_id', 'body', 'moderation_state', 'created_at'])
            ->map(function ($row) {
                $row->created_at = $row->created_at ? Carbon::parse($row->created_at) : null;

                return $row;
            }));

        $visitor->setAttribute('recent_votes', DB::table('roadmap_votes as v')
            ->join('roadmap_posts as p', 'p.id', '=', 'v.post_id')
            ->where('v.visitor_id', $visitor->id)
            ->orderByDesc('v.id')->limit(10)
            ->get(['v.post_id', 'p.title as post_title', 'v.created_at'])
            ->map(function ($row) {
                $row->created_at = $row->created_at ? Carbon::parse($row->created_at) : null;

                return $row;
            }));

        $ip = $visitor->last_ip_hash ?: $visitor->first_ip_hash;
        $visitor->setAttribute('same_ip_visitors', $ip
            ? Visitor::query()->where('id', '!=', $visitor->id)->where(fn ($w) => $w->where('first_ip_hash', $ip)->orWhere('last_ip_hash', $ip))->count()
            : 0);

        return (new AdminVisitorDetailResource($visitor))->resolve();
    }
}
