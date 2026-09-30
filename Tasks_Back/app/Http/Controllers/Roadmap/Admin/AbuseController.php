<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\AbuseEventsRequest;
use App\Http\Requests\Roadmap\Admin\BulkRemoveRequest;
use App\Http\Resources\Roadmap\Admin\AdminAbuseEventResource;
use App\Models\Roadmap\AbuseEvent;
use App\Services\Roadmap\ModerationService;
use Illuminate\Http\JsonResponse;

class AbuseController extends AdminController
{
    public function __construct(private ModerationService $moderation) {}

    /** Remove everything a visitor / ip hash contributed (one transaction, counters recomputed). */
    public function bulkRemove(BulkRemoveRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validated();

            $result = $this->moderation->bulkRemove(
                $data['by'],
                $data['value'],
                $data['remove'],
                (bool) ($data['ban'] ?? false),
                $data['reason'] ?? null,
                $request->user()
            );

            return $this->ok($result, 'Content removed successfully');
        });
    }

    public function events(AbuseEventsRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $f = $request->validated();

            $page = AbuseEvent::query()
                ->when(! empty($f['type']), fn ($q) => $q->where('type', $f['type']))
                ->when(! empty($f['visitor_id']), fn ($q) => $q->where('visitor_id', $f['visitor_id']))
                ->when(! empty($f['ip_hash']), fn ($q) => $q->where('ip_hash', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['ip_hash']).'%'))
                ->when(! empty($f['board_id']), fn ($q) => $q->where('board_id', (int) $f['board_id']))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate((int) ($f['per_page'] ?? 25));

            return $this->paginated($page, AdminAbuseEventResource::collection($page->getCollection())->resolve(), 'Abuse events retrieved successfully');
        });
    }
}
