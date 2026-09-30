<?php

namespace App\Services\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\SlugRedirect;
use App\Support\Roadmap\TextSanitizer;
use Illuminate\Support\Str;

/**
 * Builds the data behind the crawler-facing HTML shell: titles, descriptions, canonical URLs,
 * Open Graph / Twitter tags and JSON-LD. Pure data, no HTML: the Blade views escape every value.
 */
class SeoService
{
    public function __construct(private SettingsService $settings) {}

    // ─── URLs ────────────────────────────────────────────────────────

    public function url(string $path = ''): string
    {
        return rtrim((string) config('roadmap.frontend_url'), '/').'/'.ltrim($path, '/');
    }

    public function boardPath(Board|string $board): string
    {
        return '/roadmap/'.($board instanceof Board ? $board->slug : $board);
    }

    public function postPath(Board $board, Post $post): string
    {
        return $this->boardPath($board).'/p/'.$post->number.'-'.$post->slug;
    }

    // ─── Resolution (with redirects) ─────────────────────────────────

    /** @return array{board: ?Board, redirect: ?string} redirect = the current board slug when $slug is an old one */
    public function resolveBoard(string $slug): array
    {
        $board = Board::query()->where('slug', $slug)->first();
        if ($board) {
            return ['board' => $board->is_archived ? null : $board, 'redirect' => null];
        }

        $target = SlugRedirect::query()->where('kind', 'board')->where('old_key', $slug)->first();
        $moved = $target ? Board::query()->where('is_archived', false)->find($target->target_id) : null;

        return ['board' => null, 'redirect' => $moved?->slug];
    }

    /**
     * Post lookup for "/p/{number}-{slug}".
     *
     * @return array{post: ?Post, redirect: ?string} redirect = SPA path when this URL is stale
     */
    public function resolvePost(Board $board, string $numberSlug): array
    {
        if (preg_match('/^(\d{1,9})(?:-(.*))?$/s', $numberSlug, $m) !== 1) {
            return ['post' => null, 'redirect' => null];
        }
        $number = (int) $m[1];
        $slug = $m[2] ?? '';

        $post = Post::query()->where('board_id', $board->id)->where('number', $number)->first();

        if (! $post) {
            // Moved to another board?
            $redirect = SlugRedirect::query()->where('kind', 'post')->where('old_key', $board->id.':'.$number)->first();
            $moved = $redirect ? Post::query()->find($redirect->target_id) : null;

            return $this->finalisePost($moved, 'moved');
        }

        return $this->finalisePost($post, $slug === $post->slug ? null : 'stale');
    }

    // ─── Page data ───────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function home(): array
    {
        $s = $this->settings->global();
        $boards = Board::query()->where('is_archived', false)
            ->withCount(['posts as posts_count' => fn ($q) => $q->publiclyVisible()])
            ->orderBy('sort_order')->orderBy('id')->get();

        $title = $s['site']['name'].($s['site']['tagline'] ? ' - '.$s['site']['tagline'] : '');

        return $this->page([
            'view' => 'home',
            'title' => $this->withSuffix($title, $s, false),
            'heading' => $s['site']['hero_title'],
            'description' => $this->description($s['seo']['meta_description'] ?: ($s['site']['hero_subtitle'] ?: $s['site']['tagline'] ?: $s['site']['name'])),
            'path' => '/roadmap',
            'type' => 'website',
            'boards' => $boards,
            'changelogUrl' => $this->url('/changelog'),
            'breadcrumbs' => [['Roadmap', '/roadmap']],
            'jsonld' => [[
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $s['site']['name'],
                'url' => $this->url('/roadmap'),
                'description' => $this->description($s['site']['hero_subtitle'] ?: $s['site']['tagline'] ?: ''),
            ]],
        ], $s);
    }

    /** @return array<string,mixed> */
    public function board(Board $board, bool $roadmap = false): array
    {
        $s = $this->settings->forBoard($board->id);

        if ($roadmap) {
            $columns = $board->statuses()->where('is_roadmap_column', true)->get()->map(function ($status) use ($board) {
                return [
                    'status' => $status,
                    'posts' => Post::query()->publiclyVisible()->where('board_id', $board->id)->where('status_id', $status->id)
                        ->orderByDesc('is_pinned')->orderBy('roadmap_order')->orderByDesc('votes_count')->limit(10)->get(),
                ];
            })->all();
            $path = $this->boardPath($board).'/roadmap';
            $heading = $board->name.' roadmap';
            $desc = 'What is planned, in progress and shipped for '.$board->name.'.';
            $crumb = [[$board->name, $this->boardPath($board)], ['Roadmap', $path]];

            return $this->page([
                'view' => 'roadmap',
                'title' => $this->withSuffix($heading, $s),
                'heading' => $heading,
                'description' => $this->description($s['seo']['meta_description'] ?: $desc),
                'path' => $path,
                'type' => 'website',
                'board' => $board,
                'columns' => $columns,
                'postUrl' => fn (Post $p) => $this->url($this->postPath($board, $p)),
                'breadcrumbs' => array_merge([['Roadmap', '/roadmap']], $crumb),
                'jsonld' => [$this->collectionPage($heading, $path, $desc)],
            ], $s);
        }

        $posts = Post::query()->with('status')->publiclyVisible()->where('board_id', $board->id)
            ->orderByDesc('is_pinned')->orderByDesc('votes_count')->orderByDesc('id')->limit(30)->get();
        $path = $this->boardPath($board);
        $desc = $board->description ?: 'Ideas and feature requests for '.$board->name.'. Vote on what we build next.';

        return $this->page([
            'view' => 'board',
            'title' => $this->withSuffix($board->name.' - feature requests', $s),
            'heading' => $board->name,
            'description' => $this->description($s['seo']['meta_description'] ?: $desc),
            'path' => $path,
            'type' => 'website',
            'board' => $board,
            'posts' => $posts,
            'postUrl' => fn (Post $p) => $this->url($this->postPath($board, $p)),
            'breadcrumbs' => [['Roadmap', '/roadmap'], [$board->name, $path]],
            'jsonld' => [$this->collectionPage($board->name, $path, $desc)],
        ], $s);
    }

    /** @return array<string,mixed> */
    public function post(Post $post): array
    {
        $post->loadMissing(['board', 'status', 'tags']);
        $board = $post->board;
        $s = $this->settings->forBoard($board->id);
        $path = $this->postPath($board, $post);

        $comments = $post->comments()->where('moderation_state', 'approved')->orderBy('id')->limit(30)->get();
        $description = $this->description($post->body ?: $post->title);

        $author = $post->author_name ?: 'Anonymous';
        $jsonld = [
            '@context' => 'https://schema.org',
            '@type' => 'DiscussionForumPosting',
            'headline' => Str::limit($post->title, 110, ''),
            'text' => Str::limit((string) ($post->body ?: $post->title), 500),
            'url' => $this->url($path),
            'datePublished' => ($post->published_at ?? $post->created_at)?->toIso8601ZuluString(),
            'dateModified' => ($post->last_activity_at ?? $post->published_at ?? $post->created_at)?->toIso8601ZuluString(),
            'author' => ['@type' => 'Person', 'name' => $author],
            'commentCount' => (int) $post->comments_count,
            'interactionStatistic' => [
                ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/LikeAction', 'userInteractionCount' => (int) $post->votes_count],
                ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/CommentAction', 'userInteractionCount' => (int) $post->comments_count],
            ],
        ];

        return $this->page([
            'view' => 'post',
            'title' => $this->withSuffix($post->title, $s),
            'heading' => $post->title,
            'description' => $description,
            'path' => $path,
            'type' => 'article',
            'board' => $board,
            'post' => $post,
            'comments' => $comments,
            'teamName' => $s['site']['team_name'],
            'breadcrumbs' => [['Roadmap', '/roadmap'], [$board->name, $this->boardPath($board)], [$post->title, $path]],
            'jsonld' => [$jsonld],
        ], $s);
    }

    /** @return array<string,mixed> */
    public function changelogIndex(): array
    {
        $s = $this->settings->global();
        $entries = ChangelogEntry::query()->live()->with('board:id,slug,name')->orderByDesc('published_at')->orderByDesc('id')->limit(30)->get();
        $desc = 'Latest updates, improvements and fixes.';

        return $this->page([
            'view' => 'changelog-index',
            'title' => $this->withSuffix('Changelog', $s),
            'heading' => 'Changelog',
            'description' => $this->description($s['seo']['meta_description'] ?: $desc),
            'path' => '/changelog',
            'type' => 'website',
            'entries' => $entries,
            'entryUrl' => fn (ChangelogEntry $e) => $this->url('/changelog/'.$e->slug),
            'breadcrumbs' => [['Roadmap', '/roadmap'], ['Changelog', '/changelog']],
            'feedUrl' => $s['features']['rss'] ? $this->url('/changelog/feed.xml') : null,
            'jsonld' => [$this->collectionPage('Changelog', '/changelog', $desc)],
        ], $s);
    }

    /** @return array<string,mixed> */
    public function changelogEntry(ChangelogEntry $entry): array
    {
        $entry->loadMissing('board:id,slug,name');
        $s = $this->settings->global();
        $path = '/changelog/'.$entry->slug;

        return $this->page([
            'view' => 'changelog-entry',
            'title' => $this->withSuffix($entry->title, $s),
            'heading' => $entry->title,
            'description' => $this->description($entry->summary ?: strip_tags((string) $entry->body_html) ?: $entry->title),
            'path' => $path,
            'type' => 'article',
            'entry' => $entry,
            'breadcrumbs' => [['Roadmap', '/roadmap'], ['Changelog', '/changelog'], [$entry->title, $path]],
            'jsonld' => [[
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => Str::limit($entry->title, 110, ''),
                'description' => $this->description($entry->summary ?: $entry->title),
                'url' => $this->url($path),
                'datePublished' => $entry->published_at?->toIso8601ZuluString(),
                'dateModified' => ($entry->updated_at ?? $entry->published_at)?->toIso8601ZuluString(),
                'author' => ['@type' => 'Organization', 'name' => $s['site']['team_name']],
                'publisher' => ['@type' => 'Organization', 'name' => $s['site']['name']],
            ]],
        ], $s);
    }

    /** @return array<string,mixed> */
    public function notFound(): array
    {
        $s = $this->settings->global();

        return $this->page([
            'view' => 'not-found',
            'title' => 'Page not found'.$this->suffixPart($s),
            'heading' => 'Page not found',
            'description' => 'This page does not exist or is no longer available.',
            'path' => '/roadmap',
            'type' => 'website',
            'breadcrumbs' => [],
            'jsonld' => [],
            'forceNoindex' => true,
        ], $s);
    }

    /** OG image: settings og image, then the logo, then the static default. */
    public function ogImage(array $settings): string
    {
        $assets = $this->settings->assets($settings['branding']);

        return $assets['og_image_url'] ?: ($assets['logo_url'] ?: $this->url('/og-default.png'));
    }

    // ─── Internals ───────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $page
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    private function page(array $page, array $settings): array
    {
        $canonical = $this->url($page['path']);
        $crawlOff = ! ($settings['seo']['indexable'] ?? true) || ! empty($page['forceNoindex']) || request()->query() !== [];

        $page += [
            'canonical' => $canonical,
            'robots' => $crawlOff ? 'noindex,follow' : 'index,follow',
            'siteName' => $settings['site']['name'],
            'ogImage' => $this->ogImage($settings),
            'faviconUrl' => $this->settings->assets($settings['branding'])['favicon_url'],
            'themeColor' => $settings['branding']['primary'],
            'spaUrl' => $canonical,
        ];

        // BreadcrumbList from [label, path] pairs.
        if (! empty($page['breadcrumbs'])) {
            $items = [];
            foreach (array_values($page['breadcrumbs']) as $i => [$name, $path]) {
                $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $this->url($path)];
            }
            $page['jsonld'][] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }

        $page['breadcrumbLinks'] = array_map(fn ($c) => [$c[0], $this->url($c[1])], $page['breadcrumbs']);

        return $page;
    }

    private function collectionPage(string $name, string $path, string $description): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'url' => $this->url($path),
            'description' => $this->description($description),
        ];
    }

    /** @return array{post: ?Post, redirect: ?string} */
    private function finalisePost(?Post $post, ?string $stale): array
    {
        if (! $post) {
            return ['post' => null, 'redirect' => null];
        }

        // Merged: send crawlers to the surviving post.
        if ($post->merged_into_post_id !== null) {
            $target = Post::query()->with('board')->find($post->merged_into_post_id);
            while ($target && $target->merged_into_post_id !== null) {
                $target = Post::query()->with('board')->find($target->merged_into_post_id);
            }

            return $target && $target->moderation_state === 'approved' && ! $target->board->is_archived
                ? ['post' => null, 'redirect' => $this->postPath($target->board, $target)]
                : ['post' => null, 'redirect' => null];
        }

        if ($post->moderation_state !== 'approved') {
            return ['post' => null, 'redirect' => null];
        }

        $post->loadMissing('board');
        if ($post->board->is_archived) {
            return ['post' => null, 'redirect' => null];
        }

        return $stale === null
            ? ['post' => $post, 'redirect' => null]
            : ['post' => null, 'redirect' => $this->postPath($post->board, $post)];
    }

    /** "Page title | suffix" (suffix from settings, else the site name). */
    private function withSuffix(string $title, array $settings, bool $useSiteName = true): string
    {
        $suffix = $this->suffixPart($settings, $useSiteName);

        return Str::limit($title, 110, '').$suffix;
    }

    private function suffixPart(array $settings, bool $useSiteName = true): string
    {
        $suffix = trim((string) ($settings['seo']['title_suffix'] ?? ''));
        if ($suffix === '') {
            return $useSiteName ? ' | '.$settings['site']['name'] : '';
        }

        return preg_match('/^[\p{L}\p{N}]/u', $suffix) === 1 ? ' | '.$suffix : ' '.$suffix;
    }

    /** Meta description: plain text, whitespace collapsed, at most 160 characters. */
    private function description(?string $text): string
    {
        $text = TextSanitizer::clean(strip_tags((string) $text));

        return Str::limit($text, 157, '...');
    }
}
