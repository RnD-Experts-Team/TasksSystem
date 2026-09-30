<?php

namespace App\Http\Middleware\Roadmap;

use App\Models\Roadmap\Visitor;
use App\Support\Roadmap\IpHasher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the anonymous visitor from `X-Visitor-Token` (sha256 lookup, the raw token is never stored).
 *
 * ResolveVisitor:required  → 401 visitor_token_required | visitor_token_invalid
 * ResolveVisitor:optional  → a missing/unknown token is ignored
 *
 * Banned visitors are NOT rejected here: shadow bans are applied inside the write services.
 */
class ResolveVisitor
{
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $token = trim((string) $request->headers->get('X-Visitor-Token', ''));

        if ($token === '') {
            return $mode === 'required'
                ? $this->reject('visitor_token_required', 'A visitor token is required.')
                : $next($request);
        }

        if (strlen($token) > 128) {
            return $mode === 'required'
                ? $this->reject('visitor_token_invalid', 'The visitor token is not valid.')
                : $next($request);
        }

        $visitor = Visitor::query()->where('token_hash', hash('sha256', $token))->first();

        if (! $visitor) {
            return $mode === 'required'
                ? $this->reject('visitor_token_invalid', 'The visitor token is not valid.')
                : $next($request);
        }

        $this->touch($request, $visitor);
        $request->attributes->set('roadmap.visitor', $visitor);

        return $next($request);
    }

    /** Refresh last_seen_at / last_ip_hash at most every 5 minutes. */
    private function touch(Request $request, Visitor $visitor): void
    {
        if ($visitor->last_seen_at !== null && $visitor->last_seen_at->gt(now()->subMinutes(5))) {
            return;
        }

        $ip = IpHasher::forRequest($request);
        Visitor::query()->whereKey($visitor->id)->update(['last_seen_at' => now(), 'last_ip_hash' => $ip]);
        $visitor->last_seen_at = now();
        $visitor->last_ip_hash = $ip;
    }

    private function reject(string $code, string $message): Response
    {
        return response()->json(['success' => false, 'data' => null, 'message' => $message, 'code' => $code], 401);
    }
}
