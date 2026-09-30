<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Services\Roadmap\VisitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitorController extends PublicApiController
{
    public function __construct(private VisitorService $visitors) {}

    /** POST /visitor: issue (201) or confirm (200) the anonymous visitor token. */
    public function issue(Request $request): JsonResponse
    {
        $result = $this->visitors->issue($request);

        return $this->ok($result, 'Visitor ready', $result['is_new'] ? 201 : 200);
    }
}
