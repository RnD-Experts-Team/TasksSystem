<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Exceptions\RoadmapException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Base of the roadmap admin controllers: the standard envelope
 * { success, data, message } (+ sibling `pagination` on lists) and one place that maps
 * exceptions to responses. Unexpected errors are logged and answered with a generic message;
 * a raw Throwable message never reaches the client.
 */
abstract class AdminController extends Controller
{
    /** Run an action, mapping business rules to 400/404 and everything unexpected to a generic 500. */
    protected function run(callable $action, string $errorMessage = 'Something went wrong.'): JsonResponse
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw $e; // default Laravel 422 body
        } catch (RoadmapException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
            ], $e->getCode() === 404 ? 404 : 400);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (\Throwable $e) {
            Log::error('Roadmap admin error', ['exception' => $e]);

            return response()->json(['success' => false, 'data' => null, 'message' => $errorMessage], 500);
        }
    }

    protected function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => $message], $status);
    }

    /** @param array<int, mixed> $items already resolved (plain arrays) */
    protected function paginated(LengthAwarePaginator $paginator, array $items, string $message = 'OK'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => array_values($items),
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

    protected function notFound(string $message = 'Not found'): JsonResponse
    {
        return response()->json(['success' => false, 'data' => null, 'message' => $message], 404);
    }
}
