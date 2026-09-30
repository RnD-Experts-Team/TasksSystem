<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\AnalyticsRequest;
use App\Services\Roadmap\AnalyticsService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends AdminController
{
    public function __construct(private AnalyticsService $analytics) {}

    public function summary(AnalyticsRequest $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $f = $request->validated();

            return $this->ok(
                $this->analytics->summary(isset($f['board_id']) ? (int) $f['board_id'] : null, (int) ($f['days'] ?? 30)),
                'Analytics retrieved successfully'
            );
        });
    }
}
