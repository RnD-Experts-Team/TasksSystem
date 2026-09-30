<?php

namespace App\Http\Middleware\Roadmap;

use App\Models\Roadmap\Visitor;
use App\Services\Roadmap\AbuseGuardService;
use App\Support\Roadmap\IpHasher;
use App\Support\Roadmap\Limits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Own throttle middleware (NOT RateLimiter::for) so it keeps working with `route:cache`.
 *
 * Buckets come from {@see Limits::throttles()} and are keyed by the hashed client IP and/or the
 * visitor id. All buckets are checked first; only when none is exhausted are they all hit
 * (a rejected request does not consume quota).
 *
 * Usage: RoadmapThrottle::class.':vote'
 */
class RoadmapThrottle
{
    public function handle(Request $request, Closure $next, string $name = 'read'): Response
    {
        $ip = IpHasher::forRequest($request);
        $visitor = $request->attributes->get('roadmap.visitor');
        $visitorId = $visitor instanceof Visitor ? $visitor->id : null;

        $keys = [];
        $retryAfter = 0;

        foreach (Limits::throttles($name) as [$scope, $max, $decay]) {
            $subject = $scope === 'visitor' ? $visitorId : $ip;
            if ($subject === null) {
                continue; // no visitor on an optional route: the IP buckets still apply
            }

            $key = "roadmap:throttle:{$name}:{$scope}:{$subject}:{$decay}";
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($key));
            }
            $keys[$key] = $decay;
        }

        if ($retryAfter > 0) {
            $this->recordSampled($name, $ip, $visitorId);

            return PublicApiGuard::limit('rate_limited', "You're going a bit fast. Please try again shortly.", $retryAfter);
        }

        foreach ($keys as $key => $decay) {
            RateLimiter::hit($key, $decay);
        }

        return $next($request);
    }

    /** Log at most one `rate_limited` abuse event per subject+route every 5 minutes. */
    private function recordSampled(string $name, string $ip, ?string $visitorId): void
    {
        if (Cache::add("roadmap:rl-event:{$name}:{$ip}:".($visitorId ?? '-'), 1, 300)) {
            app(AbuseGuardService::class)->log('rate_limited', $visitorId, $ip, null, ['route' => $name]);
        }
    }
}
