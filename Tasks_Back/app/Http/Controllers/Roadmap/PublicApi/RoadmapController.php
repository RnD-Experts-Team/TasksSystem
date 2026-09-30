<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Http\Requests\Roadmap\Public\RoadmapRequest;
use App\Http\Resources\Roadmap\Public\PublicRoadmapResource;
use App\Services\Roadmap\BoardService;
use App\Services\Roadmap\PublicRoadmapService;
use App\Support\Roadmap\Limits;
use Illuminate\Http\JsonResponse;

class RoadmapController extends PublicApiController
{
    public function __construct(private BoardService $boards, private PublicRoadmapService $roadmap) {}

    /** GET /boards/{board}/roadmap */
    public function show(RoadmapRequest $request, string $board): JsonResponse
    {
        $model = $this->boards->findBySlug($board);
        $columns = $this->roadmap->columns($model, (int) ($request->validated()['per_column'] ?? 10));

        $data = (new PublicRoadmapResource($columns, $model->slug, Limits::teamName()))->resolve();

        return $this->ok($data, 'Roadmap retrieved successfully');
    }
}
