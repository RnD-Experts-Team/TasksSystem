<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Search over the public posts of a board.
 *
 * Driver `like` (default, portable): each whitespace token becomes `title LIKE OR body LIKE`
 * with % _ (and the escape char) escaped. Ranking for "similar" suggestions happens in PHP over <= 60 candidates.
 */
class PostSearch
{
    public const MAX_TOKENS = 6;

    public const CANDIDATES = 60;

    /** Filler words that say nothing about what an idea is about (English only for now). */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'from', 'into', 'onto', 'over', 'that', 'this', 'these', 'those',
        'any', 'all', 'our', 'your', 'you', 'can', 'could', 'should', 'would', 'add', 'have', 'has',
        'are', 'was', 'were', 'not', 'but', 'too', 'also', 'use', 'make', 'able', 'let', 'get', 'more',
    ];

    /** @return array<int,string> */
    public function tokens(?string $q): array
    {
        $q = trim((string) $q);
        if ($q === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique(array_map(
            static fn ($t) => mb_strtolower($t),
            array_filter($tokens, static fn ($t) => mb_strlen($t) >= 1)
        ))), 0, self::MAX_TOKENS);
    }

    /**
     * The words that carry meaning for similarity: no stopwords, no 1-2 letter filler. Falls back
     * to all tokens when nothing meaningful is left (e.g. someone types just "UI").
     *
     * @param  array<int,string>  $tokens
     * @return array<int,string>
     */
    public function meaningfulTokens(array $tokens): array
    {
        $meaningful = array_values(array_filter(
            $tokens,
            static fn (string $t) => mb_strlen($t) >= 3 && ! in_array($t, self::STOPWORDS, true)
        ));

        return $meaningful !== [] ? $meaningful : $tokens;
    }

    /**
     * Escape LIKE wildcards. `!` is the ESCAPE character (a backslash would need different
     * quoting on sqlite and MySQL); backslash itself is not special under `ESCAPE '!'`.
     */
    public function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** Every token must appear in the title or the body (AND across tokens). */
    public function apply(Builder $query, ?string $q): Builder
    {
        foreach ($this->tokens($q) as $token) {
            $like = '%'.$this->escapeLike($token).'%';
            $query->where(function (Builder $w) use ($like) {
                $w->whereRaw("roadmap_posts.title like ? escape '!'", [$like])
                    ->orWhereRaw("roadmap_posts.body like ? escape '!'", [$like]);
            });
        }

        return $query;
    }

    /**
     * Up to 5 posts similar to $q, best first. Only publicly visible posts of the board.
     *
     * @return Collection<int,Post>
     */
    public function similar(Board $board, string $q, int $limit = 5): Collection
    {
        $tokens = $this->meaningfulTokens($this->tokens($q));
        if ($tokens === []) {
            return collect();
        }

        // A candidate must share at least half of the meaningful words, so a single common word
        // ("tasks") does not surface unrelated ideas.
        $minMatches = max(1, (int) ceil(count($tokens) / 2));

        $candidates = Post::query()
            ->publiclyVisible()
            ->where('board_id', $board->id)
            ->where(function (Builder $w) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%'.$this->escapeLike($token).'%';
                    $w->orWhereRaw("roadmap_posts.title like ? escape '!'", [$like])
                        ->orWhereRaw("roadmap_posts.body like ? escape '!'", [$like]);
                }
            })
            ->with('status:id,slug,name,color,kind')
            ->orderByDesc('votes_count')->orderByDesc('id')
            ->limit(self::CANDIDATES)
            ->get();

        return $candidates
            ->map(function (Post $post) use ($tokens) {
                $title = mb_strtolower($post->title);
                $body = mb_strtolower((string) $post->body);
                $score = 0;
                $matched = 0;
                foreach ($tokens as $token) {
                    $inTitle = str_contains($title, $token);
                    $inBody = str_contains($body, $token);
                    $score += ($inTitle ? 3 : 0) + ($inBody ? 1 : 0);
                    $matched += ($inTitle || $inBody) ? 1 : 0;
                }
                $post->setAttribute('similarity', $score);
                $post->setAttribute('matched_tokens', $matched);

                return $post;
            })
            ->filter(fn (Post $post) => $post->getAttribute('matched_tokens') >= $minMatches)
            ->sort(function (Post $a, Post $b) {
                return [$b->getAttribute('similarity'), $b->votes_count, $b->id]
                    <=> [$a->getAttribute('similarity'), $a->votes_count, $a->id];
            })
            ->take($limit)
            ->values();
    }
}
