<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use Illuminate\Support\Str;

/** RSS 2.0 feed of the live changelog (20 newest entries). */
class RssService
{
    public const ITEMS = 20;

    public function __construct(
        private SettingsService $settings,
        private SeoService $seo,
    ) {}

    public function changelogFeed(?string $boardSlug = null): string
    {
        $site = $this->settings->global()['site'];
        $board = $boardSlug ? Board::query()->where('slug', $boardSlug)->first() : null;

        $entries = ChangelogEntry::query()->live()
            ->when($board, fn ($q) => $q->where(fn ($w) => $w->where('board_id', $board->id)->orWhereNull('board_id')))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(self::ITEMS)->get();

        $w = new \XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('rss');
        $w->writeAttribute('version', '2.0');
        $w->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $w->writeAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $w->startElement('channel');

        $w->writeElement('title', $site['name'].' - Changelog');
        $w->writeElement('link', $this->seo->url('/changelog'));
        $w->writeElement('description', $site['tagline'] ?: 'Latest updates, improvements and fixes.');
        $w->writeElement('language', 'en');
        if ($entries->isNotEmpty()) {
            $w->writeElement('lastBuildDate', $entries->first()->published_at->toRssString());
        }

        $w->startElement('atom:link');
        $w->writeAttribute('href', $this->seo->url('/changelog/feed.xml'));
        $w->writeAttribute('rel', 'self');
        $w->writeAttribute('type', 'application/rss+xml');
        $w->endElement();

        foreach ($entries as $entry) {
            $link = $this->seo->url('/changelog/'.$entry->slug);

            $w->startElement('item');
            $w->writeElement('title', $entry->title);
            $w->writeElement('link', $link);
            $w->startElement('guid');
            $w->writeAttribute('isPermaLink', 'true');
            $w->text($link);
            $w->endElement();
            $w->writeElement('pubDate', $entry->published_at->toRssString());
            $w->writeElement('category', ucfirst($entry->label));
            $w->writeElement('description', $entry->summary ?: Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $entry->body_html))), 280));
            if ($entry->body_html) {
                $w->startElement('content:encoded');
                $w->writeCdata(str_replace(']]>', ']]]]><![CDATA[>', (string) $entry->body_html));
                $w->endElement();
            }
            $w->endElement();
        }

        $w->endElement();
        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }
}
