<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml for the public roadmap: boards, approved (unmerged) posts and live changelog
 * entries, each with a lastmod. Cached for one hour, capped at 50 000 URLs (sitemap limit).
 */
class SitemapService
{
    public const CACHE_KEY = 'roadmap:sitemap';

    public const MAX_URLS = 50000;

    public function __construct(
        private SettingsService $settings,
        private SeoService $seo,
    ) {}

    public function xml(): string
    {
        return Cache::remember(self::CACHE_KEY, 3600, fn () => $this->build());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function build(): string
    {
        $writer = new \XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $count = 0;
        $add = function (string $path, ?\DateTimeInterface $lastmod, string $priority, string $freq) use ($writer, &$count) {
            if ($count >= self::MAX_URLS) {
                return;
            }
            $count++;
            $writer->startElement('url');
            $writer->writeElement('loc', $this->seo->url($path));
            if ($lastmod) {
                $writer->writeElement('lastmod', Carbon::instance($lastmod)->toIso8601ZuluString());
            }
            $writer->writeElement('changefreq', $freq);
            $writer->writeElement('priority', $priority);
            $writer->endElement();
        };

        // An unindexable site publishes an empty sitemap.
        if ($this->settings->get('seo.indexable', true)) {
            $add('/roadmap', null, '0.8', 'daily');

            $boards = Board::query()->where('is_archived', false)->orderBy('sort_order')->orderBy('id')->get();
            $lastByBoard = Post::query()->publiclyVisible()->selectRaw('board_id, MAX(COALESCE(last_activity_at, updated_at)) as last')->groupBy('board_id')->pluck('last', 'board_id');

            foreach ($boards as $board) {
                $last = $lastByBoard[$board->id] ?? null;
                $lastmod = $last ? Carbon::parse($last) : $board->updated_at;
                $add($this->seo->boardPath($board), $lastmod, '0.7', 'daily');
                if ($this->settings->get('features.roadmap', true)) {
                    $add($this->seo->boardPath($board).'/roadmap', $lastmod, '0.6', 'daily');
                }
            }

            $boardMap = $boards->keyBy('id');
            Post::query()->publiclyVisible()->whereIn('board_id', $boardMap->keys())
                ->orderBy('id')
                ->select(['id', 'board_id', 'number', 'slug', 'last_activity_at', 'updated_at', 'published_at'])
                ->chunkById(500, function ($posts) use ($add, $boardMap) {
                    foreach ($posts as $post) {
                        $add(
                            $this->seo->postPath($boardMap[$post->board_id], $post),
                            $post->last_activity_at ?? $post->updated_at ?? $post->published_at,
                            '0.6',
                            'weekly'
                        );
                    }
                });

            if ($this->settings->get('features.changelog', true)) {
                $entries = ChangelogEntry::query()->live()->orderByDesc('published_at')->get(['slug', 'published_at', 'updated_at']);
                if ($entries->isNotEmpty()) {
                    $add('/changelog', $entries->first()->published_at, '0.6', 'weekly');
                }
                foreach ($entries as $entry) {
                    $add('/changelog/'.$entry->slug, $entry->updated_at ?? $entry->published_at, '0.5', 'monthly');
                }
            }
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }
}
