<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\Visitor;
use App\Support\Roadmap\IpHasher;
use App\Support\Roadmap\Limits;
use App\Support\Roadmap\RoadmapBusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Signed, single-use, time-boxed form tokens (anti-bot).
 *
 * token = base64url(json{k: kind, b: board id, v: visitor id, t: issued-at, n: nonce}) . "." . hmac_sha256(payload)
 *
 * verify(): signature, kind, board, visitor, age within [min_seconds, max_age].
 * consume(): single use via Cache::add on the nonce.
 */
class FormTokenService
{
    /** @return array{form_token: string, min_seconds: int, max_age_seconds: int} */
    public function issue(string $kind, Board $board, Visitor $visitor): array
    {
        $payload = $this->b64(json_encode([
            'k' => $kind,
            'b' => $board->id,
            'v' => $visitor->id,
            't' => now()->getTimestamp(),
            'n' => Str::random(16),
        ], JSON_THROW_ON_ERROR));

        return [
            'form_token' => $payload.'.'.$this->sign($payload),
            'min_seconds' => Limits::minSeconds($kind),
            'max_age_seconds' => Limits::formTokenMaxAge(),
        ];
    }

    /**
     * Validates the token WITHOUT consuming it (so a "too fast" attempt can be retried).
     *
     * @return array{n: string, t: int}
     *
     * @throws RoadmapBusinessException form_expired | too_fast
     */
    public function verify(?string $token, string $kind, Board $board, Visitor $visitor): array
    {
        $claims = $this->decode($token);

        if ($claims === null
            || ($claims['k'] ?? null) !== $kind
            || (int) ($claims['b'] ?? 0) !== (int) $board->id
            || ($claims['v'] ?? null) !== $visitor->id
        ) {
            throw $this->expired();
        }

        $age = now()->getTimestamp() - (int) ($claims['t'] ?? 0);
        if ($age > Limits::formTokenMaxAge() || $age < 0) {
            throw $this->expired();
        }

        if (Cache::has($this->usedKey((string) $claims['n']))) {
            throw $this->expired();
        }

        if ($age < Limits::minSeconds($kind)) {
            throw new RoadmapBusinessException('too_fast', 'That was quick. Please take a moment and try again.');
        }

        return ['n' => (string) $claims['n'], 't' => (int) $claims['t']];
    }

    /** Marks the token as used. @throws RoadmapBusinessException form_expired when already used */
    public function consume(array $claims): void
    {
        if (! Cache::add($this->usedKey($claims['n']), 1, Limits::formTokenMaxAge() + 60)) {
            throw $this->expired();
        }
    }

    // ─── Internals ───────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function decode(?string $token): ?array
    {
        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token, 2);
        if (! hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $claims = is_string($json) ? json_decode($json, true) : null;

        return is_array($claims) && isset($claims['n'], $claims['t']) ? $claims : null;
    }

    private function sign(string $payload): string
    {
        $key = hash_hmac('sha256', 'roadmap-form-token', IpHasher::secret());

        return $this->b64(hash_hmac('sha256', $payload, $key, true));
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function usedKey(string $nonce): string
    {
        return 'roadmap:ft-used:'.$nonce;
    }

    private function expired(): RoadmapBusinessException
    {
        return new RoadmapBusinessException('form_expired', 'This form has expired. Please try again.');
    }
}
