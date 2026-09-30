<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Http\Resources\Roadmap\Public\PublicBoardDetailResource;
use App\Http\Resources\Roadmap\Public\PublicBoardSummaryResource;
use App\Services\Roadmap\BoardService;
use Illuminate\Http\JsonResponse;

class BoardController extends PublicApiController
{
    public function __construct(private BoardService $boards) {}

    public function index(): JsonResponse
    {
        $data = $this->boards->summaries()
            ->map(fn ($board) => (new PublicBoardSummaryResource($board))->resolve())
            ->values()->all();

        return $this->ok($data, 'Boards retrieved successfully');
    }

    public function show(string $board): JsonResponse
    {
        $model = $this->boards->findBySlug($board);
        $detail = $this->boards->detail($model);

        $data = (new PublicBoardDetailResource(
            $model, $detail['statuses'], $detail['tags'], $detail['status_counts'], $detail['tag_counts'], $detail['posts_count']
        ))->resolve();

        return $this->ok($data, 'Board retrieved successfully');
    }
}
