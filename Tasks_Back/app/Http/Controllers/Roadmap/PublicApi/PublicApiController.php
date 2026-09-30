<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\Roadmap\Visitor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base of the public controllers: envelope helpers only.
 * Public controllers must NOT catch exceptions or echo exception messages: PublicApiGuard does.
 */
abstract class PublicApiController extends Controller
{
    protected function ok(mixed $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => $message], $status);
    }

    /** @param  array<int,mixed>  $items */
    protected function paginated(LengthAwarePaginator $paginator, array $items, string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'message' => $message,
        ]);
    }

    /** The visitor resolved by ResolveVisitor (null when absent/unknown on optional routes). */
    protected function visitor(Request $request): ?Visitor
    {
        $visitor = $request->attributes->get('roadmap.visitor');

        return $visitor instanceof Visitor ? $visitor : null;
    }
}
