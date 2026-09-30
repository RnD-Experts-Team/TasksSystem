<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Http\Requests\Roadmap\Public\ListPostsRequest;
use App\Http\Requests\Roadmap\Public\SimilarPostsRequest;
use App\Http\Requests\Roadmap\Public\StorePostRequest;
use App\Http\Resources\Roadmap\Public\PublicPostDetailResource;
use App\Http\Resources\Roadmap\Public\PublicPostListResource;
use App\Http\Resources\Roadmap\Public\PublicResource;
use App\Http\Resources\Roadmap\Public\PublicSimilarPostResource;
use App\Models\Roadmap\Post;
use App\Services\Roadmap\BoardService;
use App\Services\Roadmap\PostService;
use App\Support\Roadmap\IpHasher;
use App\Support\Roadmap\Limits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends PublicApiController
{
    public function __construct(private BoardService $boards, private PostService $posts) {}

    /** GET /boards/{board}/posts */
    public function index(ListPostsRequest $request, string $board): JsonResponse
    {
        $model = $this->boards->findBySlug($board);
        $params = $request->validated();

        $paginator = $this->posts->paginate(
            $model,
            [
                'q' => $params['q'] ?? null,
                'sort' => $params['sort'] ?? 'top',
                'status' => $params['status'] ?? [],
                'tag' => $params['tag'] ?? [],
            ],
            (int) ($params['per_page'] ?? 15),
            (int) ($params['page'] ?? 1),
        );

        $team = Limits::teamName();
        $items = $paginator->getCollection()
            ->map(fn (Post $post) => (new PublicPostListResource($post, $model->slug, $team))->resolve())
            ->values()->all();

        return $this->paginated($paginator, $items, 'Posts retrieved successfully');
    }

    /** GET /boards/{board}/posts/similar?q= */
    public function similar(SimilarPostsRequest $request, string $board): JsonResponse
    {
        $model = $this->boards->findBySlug($board);

        $data = $this->posts->similar($model, $request->validated()['q'])
            ->map(fn (Post $post) => (new PublicSimilarPostResource($post, $model->slug))->resolve())
            ->values()->all();

        return $this->ok($data, 'Similar posts retrieved successfully');
    }

    /** GET /boards/{board}/posts/{number} */
    public function show(Request $request, string $board, int $number): JsonResponse
    {
        $model = $this->boards->findBySlug($board);
        $result = $this->posts->detail($model, $number, $this->visitor($request));

        $data = (new PublicPostDetailResource(
            $result['post'],
            Limits::teamName(),
            $result['history'],
            $result['changelog'],
            $result['owner_pending'],
            $result['viewer_state'],
        ))->resolve();

        $response = $this->ok($data, 'Post retrieved successfully');

        if ($result['owner_pending']) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    /** POST /boards/{board}/posts */
    public function store(StorePostRequest $request, string $board): JsonResponse
    {
        $result = $this->posts->submit(
            $board,
            $this->visitor($request),
            $request->validated(),
            (string) $request->input('website', ''),
            IpHasher::forRequest($request),
        );

        $data = [
            'number' => $result['number'],
            'slug' => $result['slug'],
            'moderation_state' => $result['moderation_state'],
            'url_path' => PublicResource::postPath($result['board_slug'], $result['number'], $result['slug']),
        ];

        return $this->ok($data, $result['moderation_state'] === 'approved' ? 'Your idea is live.' : 'Thanks! Your idea is awaiting review.', 201);
    }
}
