<?php

namespace App\Http\Middleware\Roadmap;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, ETag-validated caching for visitor-agnostic GET responses.
 * A response that already set its own Cache-Control (e.g. an owner's private view) is left alone.
 */
class PublicCache
{
    public function handle(Request $request, Closure $next, int|string $seconds = 15): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return $response;
        }

        if ($response->headers->hasCacheControlDirective('no-store')) {
            return $response;
        }

        $seconds = max(0, (int) $seconds);
        $etag = '"'.md5((string) $response->getContent()).'"';

        $response->headers->set('Cache-Control', "public, max-age={$seconds}, stale-while-revalidate=".($seconds * 4));
        $response->headers->set('ETag', $etag);
        $response->headers->set('Vary', 'Accept-Encoding');

        $ifNoneMatch = array_map('trim', explode(',', (string) $request->headers->get('If-None-Match', '')));
        if (in_array($etag, $ifNoneMatch, true) || in_array('W/'.$etag, $ifNoneMatch, true)) {
            $response->setNotModified();
        }

        return $response;
    }
}
