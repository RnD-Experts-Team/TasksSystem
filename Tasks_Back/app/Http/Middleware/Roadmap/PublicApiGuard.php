<?php

namespace App\Http\Middleware\Roadmap;

use App\Support\Roadmap\RoadmapBusinessException;
use App\Support\Roadmap\RoadmapLimitException;
use App\Support\Roadmap\RoadmapNotFound;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Wraps every public route. Guarantees that an anonymous caller only ever sees one of our own
 * generic envelopes: never an exception message, class name, SQL or stack trace, even with
 * APP_DEBUG=true.
 */
class PublicApiGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
            $failure = $this->rendered($response);
            if ($failure !== null) {
                // Laravel's routing pipeline already reported the exception and rendered it
                // (with debug details when APP_DEBUG=true). Replace that body with our envelope.
                $response = $this->translate($failure, $response, false);
            }
        } catch (Throwable $e) {
            // Not caught by the routing pipeline (e.g. thrown outside it): translate and report ourselves.
            $response = $this->translate($e, null, true);
        }

        // Writes (and anything else that is not a plain read) are never stored by caches.
        if (! $request->isMethodCacheable()) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    /** The exception behind an already rendered error response, if any. */
    private function rendered(Response $response): ?Throwable
    {
        $exception = $response->exception ?? null;

        return $exception instanceof Throwable ? $exception : null;
    }

    private function translate(Throwable $e, ?Response $original, bool $report): Response
    {
        return match (true) {
            // standard 422 / prepared responses pass through untouched
            $e instanceof ValidationException || $e instanceof HttpResponseException => $original ?? $this->rethrow($e),
            $e instanceof RoadmapNotFound, $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $this->error('Not found.', 404, 'not_found'),
            $e instanceof RoadmapBusinessException => $this->error($e->getMessage(), $e->status, $e->errorCode),
            $e instanceof RoadmapLimitException => $this->limit($e->errorCode, $e->getMessage(), $e->retryAfter),
            $e instanceof HttpExceptionInterface => $this->error(
                $this->genericMessage($e->getStatusCode()),
                $e->getStatusCode(),
                $e->getStatusCode() === 429 ? 'rate_limited' : null,
                $e->getHeaders()
            ),
            default => $this->unexpected($e, $report),
        };
    }

    private function unexpected(Throwable $e, bool $report): JsonResponse
    {
        if ($report) {
            report($e);
        }

        return $this->error('Something went wrong.', 500, null);
    }

    private function rethrow(Throwable $e): never
    {
        throw $e;
    }

    private function error(string $message, int $status, ?string $code, array $headers = []): JsonResponse
    {
        $body = ['success' => false, 'data' => null, 'message' => $message];
        if ($code !== null) {
            $body['code'] = $code;
        }

        // Only pass through safe headers from framework exceptions (e.g. Allow, Retry-After).
        $safe = array_intersect_key($headers, array_flip(['Allow', 'Retry-After']));

        return response()->json($body, $status, $safe);
    }

    public static function limit(string $code, string $message, int $retryAfter): JsonResponse
    {
        $retryAfter = max(1, $retryAfter);

        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'code' => $code,
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => $retryAfter]);
    }

    private function genericMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Unauthorized.',
            403 => 'Forbidden.',
            405 => 'Method not allowed.',
            413 => 'Payload too large.',
            415 => 'Unsupported media type.',
            429 => 'Too many requests.',
            default => 'Request failed.',
        };
    }
}
