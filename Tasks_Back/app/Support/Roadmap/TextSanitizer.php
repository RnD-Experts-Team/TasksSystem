<?php

namespace App\Support\Roadmap;

use Illuminate\Support\Str;
use Normalizer;

/**
 * Sanitises visitor-supplied plain text. Output is ALWAYS treated as text by the frontend
 * (never HTML); this class removes tricks, it does not "escape" for HTML.
 */
class TextSanitizer
{
    public const RESERVED_NAMES = ['team', 'admin', 'administrator', 'official', 'staff', 'support', 'moderator', 'mod', 'pne'];

    /**
     * NFC-normalise, strip control / zero-width / bidi characters and collapse whitespace.
     *
     * @param  bool  $multiline  keep (collapsed) line breaks: bodies. Titles and names use false.
     */
    public static function clean(?string $text, bool $multiline = false): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        if (class_exists(Normalizer::class)) {
            $normalised = Normalizer::normalize($text, Normalizer::FORM_C);
            if (is_string($normalised)) {
                $text = $normalised;
            }
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Cc = control chars (keep \n and \t for now), Cf = zero-width / bidi / BOM / soft hyphen, Zl/Zp line separators.
        $text = (string) preg_replace('/[^\P{Cc}\n\t]|\p{Cf}|\p{Zl}|\p{Zp}/u', '', $text);

        if ($multiline) {
            $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);          // collapse spaces/tabs
            $text = (string) preg_replace('/ ?\n ?/u', "\n", $text);
            $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        } else {
            $text = (string) preg_replace('/\s+/u', ' ', $text);
        }

        return trim($text);
    }

    /** Code-point length (not bytes). */
    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /** Returns an error message when a visitor-chosen display name is not acceptable, null when fine. */
    public static function authorNameError(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        if (preg_match('/[<>]/u', $name) || self::countLinks($name) > 0) {
            return 'The name may not contain tags or links.';
        }

        $normalised = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '');
        $teamName = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', Limits::teamName()) ?? '');
        foreach (array_merge(self::RESERVED_NAMES, $teamName !== '' ? [$teamName] : []) as $reserved) {
            $reserved = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $reserved) ?? '');
            if ($reserved !== '' && $normalised === $reserved) {
                return 'That name is reserved.';
            }
        }

        return null;
    }

    public static function countLinks(string $text): int
    {
        $pattern = '~(?:https?://|ftp://|www\.)\S+|\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:com|net|org|io|co|info|biz|xyz|top|ru|cn|me|app|dev|ly|link|site|online|shop|club|cc|tk|ml|ga|cf|gq|click|live)\b(?:/\S*)?~iu';

        return (int) preg_match_all($pattern, $text);
    }

    /** Whole-word, case-insensitive match against the blocklist. Returns the matched term or null. */
    public static function blockedTerm(string $text, array $blocklist): ?string
    {
        foreach ($blocklist as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $regex = '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu';
            if (@preg_match($regex, $text) === 1) {
                return $term;
            }
        }

        return null;
    }

    /** sha1 over the lower-cased alphanumeric skeleton of the parts (char(40)). */
    public static function contentHash(string ...$parts): string
    {
        $skeleton = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', implode('|', $parts)) ?? '');

        return sha1($skeleton);
    }

    public static function excerpt(?string $text, int $length = 200): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
    }

    /** ASCII slug for URLs (max 100). Falls back to "post". */
    public static function slug(string $title, int $max = 100): string
    {
        $slug = Str::slug(Str::limit($title, 200, ''), '-', 'en');
        $slug = trim(mb_substr($slug, 0, $max), '-');

        return $slug !== '' ? $slug : 'post';
    }
}
