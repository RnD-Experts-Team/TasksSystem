<?php

namespace App\Support\Roadmap;

use Illuminate\Http\Request;

/**
 * Resolves the real client IP without trusting spoofable headers by default.
 *
 * REMOTE_ADDR is used unless it belongs to `roadmap.trusted_proxies`; only then the
 * right-most entry of `roadmap.ip_header` that is NOT itself a trusted proxy is used.
 */
class ClientIp
{
    public static function resolve(Request $request): string
    {
        $remote = (string) $request->server->get('REMOTE_ADDR', '');
        if (! filter_var($remote, FILTER_VALIDATE_IP)) {
            $remote = '0.0.0.0';
        }

        $trusted = (array) config('roadmap.trusted_proxies', []);
        if (! self::inRanges($remote, $trusted)) {
            return $remote;
        }

        $header = (string) $request->headers->get((string) config('roadmap.ip_header', 'X-Forwarded-For'), '');
        if ($header === '') {
            return $remote;
        }

        $entries = array_reverse(array_map('trim', explode(',', $header)));
        foreach ($entries as $candidate) {
            if (! filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (! self::inRanges($candidate, $trusted)) {
                return $candidate;
            }
        }

        return $remote;
    }

    /** @param  array<int,string>  $ranges  CIDR blocks or single IPs */
    public static function inRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }

        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        $bits = $bits === null ? $maxBits : (int) $bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
