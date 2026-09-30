<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Http\Requests\Roadmap\Public\ListChangelogRequest;
use App\Http\Resources\Roadmap\Public\PublicChangelogDetailResource;
use App\Http\Resources\Roadmap\Public\PublicChangelogListResource;
use App\Models\Roadmap\ChangelogEntry;
use App\Services\Roadmap\PublicChangelogService;
use Illuminate\Http\JsonResponse;

class ChangelogController extends PublicApiController
{
    public function __construct(private PublicChangelogService $changelog) {}

    /** GET /changelog */
    public function index(ListChangelogRequest $request): JsonResponse
    {
        $params = $request->validated();

        $paginator = $this->changelog->list(
            $params['board'] ?? null,
            $params['label'] ?? null,
            (int) ($params['per_page'] ?? 10),
            (int) ($params['page'] ?? 1),
        );

        $items = $paginator->getCollection()
            ->map(fn (ChangelogEntry $entry) => (new PublicChangelogListResource($entry))->resolve())
            ->values()->all();

        return $this->paginated($paginator, $items, 'Changelog retrieved successfully');
    }

    /** GET /changelog/{slug} */
    public function show(string $slug): JsonResponse
    {
        $entry = $this->changelog->find($slug);
        $data = (new PublicChangelogDetailResource($entry, $this->changelog->relatedPosts($entry)))->resolve();

        return $this->ok($data, 'Entry retrieved successfully');
    }
}
