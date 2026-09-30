<?php

namespace App\Support\Roadmap;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Pseudonymises client IPs. Raw IPs are never stored or logged.
 *
 * HMAC-SHA256 with a per-month derived key (so hashes cannot be linked across months),
 * IPv6 masked to its /64, truncated to 128 bits (32 hex chars).
 */
class IpHasher
{
    public static function hash(string $ip, ?CarbonInterface $at = null): string
    {
        $month = ($at ?? now())->copy()->utc()->format('Y-m');
        $monthKey = hash_hmac('sha256', 'roadmap-ip:'.$month, self::secret());

        return substr(hash_hmac('sha256', self::normalise($ip), $monthKey), 0, 32);
    }

    /** Hash for the current request's client, memoised on the request. */
    public static function forRequest(Request $request): string
    {
        $cached = $request->attributes->get('roadmap.ip_hash');
        if (is_string($cached)) {
            return $cached;
        }

        $hash = self::hash(ClientIp::resolve($request));
        $request->attributes->set('roadmap.ip_hash', $hash);

        return $hash;
    }

    /** Short hash of a user agent (char(16) column). */
    public static function uaHash(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return substr(hash('sha256', $userAgent), 0, 16);
    }

    /** Master secret from config; also used to sign form tokens. */
    public static function secret(): string
    {
        $key = (string) config('roadmap.hash_key');
        if ($key === '') {
            if (app()->environment('testing')) {
                return str_repeat('t', 64);
            }

            throw new RuntimeException('ROADMAP_HASH_KEY is not configured.');
        }

        return $key;
    }

    private static function normalise(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'invalid:'.$ip;
        }

        if (strlen($bin) === 16) {
            // IPv4-mapped IPv6 (::ffff:a.b.c.d) is really IPv4.
            if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
                return (string) inet_ntop(substr($bin, 12));
            }
            // Mask to the /64 network: a single household gets a whole /64.
            $bin = substr($bin, 0, 8).str_repeat("\0", 8);
        }

        return (string) inet_ntop($bin);
    }
}
