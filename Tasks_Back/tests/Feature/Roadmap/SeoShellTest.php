<?php

namespace Tests\Feature\Roadmap;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\SlugRedirect;
use App\Models\Roadmap\Tag;
use App\Services\Roadmap\RssService;
use App\Services\Roadmap\SeoService;
use App\Services\Roadmap\SitemapService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

class SeoShellTest extends RoadmapTestCase
{
    use AdminApiTrait;

    private const SEO = '/api/seo';

    private const HOSTILE = '<script>alert(1)</script>';

    private function bot(string $path): TestResponse
    {
        return $this->get(self::SEO.$path, ['User-Agent' => 'Googlebot/2.1']);
    }

    /** @return array<int, array<string,mixed>> decoded JSON-LD blocks (fails the test on invalid JSON) */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $decoded = [];
        foreach ($m[1] as $raw) {
            $json = json_decode($raw, true);
            $this->assertNotNull($json, 'invalid JSON-LD: '.$raw);
            $decoded[] = $json;
        }

        return $decoded;
    }

    private function ldOfType(string $html, string $type): ?array
    {
        return collect($this->jsonLd($html))->firstWhere('@type', $type);
    }

    private function meta(string $html, string $attr, string $name): ?string
    {
        if (preg_match('#<meta '.$attr.'="'.preg_quote($name, '#').'" content="([^"]*)">#', $html, $m) === 1) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }

        return null;
    }

    private function title(string $html): ?string
    {
        return preg_match('#<title>(.*?)</title>#s', $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    private function canonical(string $html): ?string
    {
        return preg_match('#<link rel="canonical" href="([^"]*)">#', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
    }

    // ─── Home / board / roadmap ──────────────────────────────────────

    public function test_home_has_real_title_meta_canonical_og_twitter_and_json_ld(): void
    {
        $this->makeBoard(['slug' => 'web', 'name' => 'Web App', 'description' => 'The web app']);
        $this->makeBoard(['slug' => 'ios', 'name' => 'iOS App', 'is_archived' => true]);
        $res = $this->bot('/roadmap')->assertOk();
        $html = $res->getContent();

        $res->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertSame('PNE Roadmap - Tell us what to build next', $this->title($html));
        $this->assertSame('Vote on ideas, follow progress, and see what just shipped.', $this->meta($html, 'name', 'description'));
        $this->assertSame('https://app.test/roadmap', $this->canonical($html));
        $this->assertSame('index,follow', $this->meta($html, 'name', 'robots'));
        $this->assertSame('website', $this->meta($html, 'property', 'og:type'));
        $this->assertSame($this->title($html), $this->meta($html, 'property', 'og:title'));
        $this->assertSame('https://app.test/roadmap', $this->meta($html, 'property', 'og:url'));
        $this->assertSame('PNE Roadmap', $this->meta($html, 'property', 'og:site_name'));
        $this->assertSame('summary_large_image', $this->meta($html, 'name', 'twitter:card'));
        $this->assertSame($this->title($html), $this->meta($html, 'name', 'twitter:title'));
        $this->assertSame('https://app.test/og-default.png', $this->meta($html, 'property', 'og:image'));
        $this->assertSame('https://app.test/og-default.png', $this->meta($html, 'name', 'twitter:image'));

        $site = $this->ldOfType($html, 'WebSite');
        $this->assertSame('https://schema.org', $site['@context']);
        $this->assertSame('PNE Roadmap', $site['name']);
        $this->assertSame('https://app.test/roadmap', $site['url']);
        $crumbs = $this->ldOfType($html, 'BreadcrumbList');
        $this->assertSame('https://app.test/roadmap', $crumbs['itemListElement'][0]['item']);

        // Readable content: active boards only.
        $this->assertStringContainsString('Web App', $html);
        $this->assertStringContainsString('href="https://app.test/roadmap/web"', $html);
        $this->assertStringNotContainsString('iOS App', $html);
        $this->assertStringContainsString('<h1>What should we build next?</h1>', $html);

        // Humans are moved on by script; no zero-second meta refresh.
        $this->assertStringContainsString('location.replace("https://app.test/roadmap")', $html);
        $this->assertStringNotContainsStringIgnoringCase('http-equiv="refresh"', $html);
    }

    public function test_cache_headers_and_conditional_requests(): void
    {
        $this->makeBoard(['slug' => 'web']);

        $first = $this->bot('/roadmap')->assertOk()->assertHeader('Cache-Control', 'max-age=300, public');
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->get(self::SEO.'/roadmap', ['If-None-Match' => $etag])->assertStatus(304);
        $this->get(self::SEO.'/roadmap', ['If-None-Match' => '"different"'])->assertOk();
    }

    public function test_board_and_board_roadmap_pages_list_only_public_posts(): void
    {
        $board = $this->makeBoard(['slug' => 'web', 'name' => 'Web App', 'description' => 'All about the web app']);
        $planned = $this->statusOf($board, 'planned');
        $shown = $this->approvedPost($board, ['title' => 'Visible idea', 'votes_count' => 4, 'status_id' => $planned->id]);
        $this->makePost($board, ['title' => 'Pending idea', 'moderation_state' => 'pending', 'status_id' => $planned->id]);
        $this->makePost($board, ['title' => 'Rejected idea', 'moderation_state' => 'rejected']);
        $this->makePost($board, ['title' => 'Spam idea', 'moderation_state' => 'spam']);
        $this->approvedPost($board, ['title' => 'Merged idea', 'merged_into_post_id' => $shown->id]);

        $html = $this->bot('/roadmap/web')->assertOk()->getContent();
        $this->assertSame('Web App - feature requests | PNE Roadmap', $this->title($html));
        $this->assertSame('All about the web app', $this->meta($html, 'name', 'description'));
        $this->assertSame('https://app.test/roadmap/web', $this->canonical($html));
        $this->assertStringContainsString('Visible idea', $html);
        $this->assertStringContainsString('href="https://app.test/roadmap/web/p/'.$shown->number.'-visible-idea"', $html);
        foreach (['Pending idea', 'Rejected idea', 'Spam idea', 'Merged idea'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
        $names = array_column($this->ldOfType($html, 'BreadcrumbList')['itemListElement'], 'name');
        $this->assertSame(['Roadmap', 'Web App'], $names);

        $rm = $this->bot('/roadmap/web/roadmap')->assertOk()->getContent();
        $this->assertSame('Web App roadmap | PNE Roadmap', $this->title($rm));
        $this->assertSame('https://app.test/roadmap/web/roadmap', $this->canonical($rm));
        $this->assertStringContainsString('<h2>Planned</h2>', $rm);
        $this->assertStringContainsString('Visible idea', $rm);
        $this->assertStringNotContainsString('Pending idea', $rm);
    }

    // ─── Post page ───────────────────────────────────────────────────

    public function test_post_page_has_discussion_json_ld_response_and_public_comments_only(): void
    {
        $board = $this->makeBoard(['slug' => 'web', 'name' => 'Web App']);
        $tag = Tag::create(['board_id' => $board->id, 'name' => 'UX', 'slug' => 'ux']);
        $post = $this->approvedPost($board, [
            'title' => 'Dark mode please',
            'body' => "I would love a dark theme.\nSecond line.",
            'author_name' => 'Sam',
            'votes_count' => 12,
            'comments_count' => 1,
            'response_html' => '<p>We <strong>are</strong> on it.</p>',
            'response_md' => 'We **are** on it.',
        ]);
        $post->tags()->attach($tag->id);
        $this->comment($post->id, null, ['body' => 'Public comment', 'author_name' => 'Kim']);
        $this->comment($post->id, null, ['body' => 'Pending comment', 'moderation_state' => 'pending']);
        $this->comment($post->id, null, ['body' => 'Spam comment', 'moderation_state' => 'spam']);
        $this->comment($post->id, null, ['body' => 'Official reply', 'is_admin' => true, 'author_name' => 'Real Admin Name']);

        $res = $this->bot("/roadmap/web/p/{$post->number}-dark-mode-please")->assertOk();
        $html = $res->getContent();

        $this->assertSame('Dark mode please | PNE Roadmap', $this->title($html));
        $this->assertSame('I would love a dark theme. Second line.', $this->meta($html, 'name', 'description'));
        $this->assertSame("https://app.test/roadmap/web/p/{$post->number}-dark-mode-please", $this->canonical($html));
        $this->assertSame('article', $this->meta($html, 'property', 'og:type'));
        $this->assertSame('index,follow', $this->meta($html, 'name', 'robots'));

        $ld = $this->ldOfType($html, 'DiscussionForumPosting');
        $this->assertSame('Dark mode please', $ld['headline']);
        $this->assertSame('Sam', $ld['author']['name']);
        $this->assertSame("https://app.test/roadmap/web/p/{$post->number}-dark-mode-please", $ld['url']);
        $this->assertSame(1, $ld['commentCount']);
        $this->assertSame(12, $ld['interactionStatistic'][0]['userInteractionCount']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $ld['datePublished']);
        $names = array_column($this->ldOfType($html, 'BreadcrumbList')['itemListElement'], 'name');
        $this->assertSame(['Roadmap', 'Web App', 'Dark mode please'], $names);

        // Readable body, the server-sanitised official response, approved comments only.
        $this->assertStringContainsString('I would love a dark theme.<br />', $html);
        $this->assertStringContainsString('<p>We <strong>are</strong> on it.</p>', $html);
        $this->assertStringContainsString('Public comment', $html);
        $this->assertStringContainsString('Official reply', $html);
        $this->assertStringContainsString('PNE Team', $html);
        $this->assertStringNotContainsString('Real Admin Name', $html, 'admin identity never shown');
        $this->assertStringNotContainsString('Pending comment', $html);
        $this->assertStringNotContainsString('Spam comment', $html);
        $this->assertStringContainsString('Tags: UX', $html);
    }

    public function test_meta_description_is_at_most_160_characters(): void
    {
        $board = $this->makeBoard(['slug' => 'web']);
        $post = $this->approvedPost($board, ['title' => 'Long', 'body' => str_repeat('word ', 200)]);

        $html = $this->bot("/roadmap/web/p/{$post->number}-long")->assertOk()->getContent();
        $desc = $this->meta($html, 'name', 'description');

        $this->assertLessThanOrEqual(160, mb_strlen($desc));
        $this->assertStringEndsWith('...', $desc);
    }

    public function test_settings_drive_title_suffix_description_and_og_image(): void
    {
        Storage::fake('public');
        $this->asAdmin();
        $board = $this->makeBoard(['slug' => 'web']);
        $post = $this->approvedPost($board, ['title' => 'Idea']);
        $path = "/roadmap/web/p/{$post->number}-idea";

        $this->putJson(self::ADMIN_API.'/settings', ['scope' => 'global', 'data' => [
            'seo' => ['title_suffix' => '| Acme Feedback', 'meta_description' => 'Acme feedback portal.'],
            'site' => ['name' => 'Acme'],
        ]])->assertOk();

        $html = $this->bot($path)->assertOk()->getContent();
        $this->assertSame('Idea | Acme Feedback', $this->title($html));
        $this->assertSame('Acme', $this->meta($html, 'property', 'og:site_name'));
        $this->assertSame('Acme feedback portal.', $this->meta($this->bot('/roadmap/web')->getContent(), 'name', 'description'));

        // A bare word suffix is joined with a separator.
        $this->putJson(self::ADMIN_API.'/settings', ['scope' => 'global', 'data' => ['seo' => ['title_suffix' => 'Acme']]])->assertOk();
        $this->assertSame('Idea | Acme', $this->title($this->bot($path)->getContent()));

        // og image: default -> logo -> og image.
        $default = 'https://app.test/og-default.png';
        $this->assertSame($default, $this->meta($this->bot($path)->getContent(), 'property', 'og:image'));

        $logo = $this->post(self::ADMIN_API.'/settings/asset', ['type' => 'logo', 'file' => UploadedFile::fake()->image('l.png', 100, 100)], ['Accept' => 'application/json'])->json('data.url');
        $this->assertSame($logo, $this->meta($this->bot($path)->getContent(), 'property', 'og:image'));

        $og = $this->post(self::ADMIN_API.'/settings/asset', ['type' => 'og', 'file' => UploadedFile::fake()->image('o.png', 1200, 630)], ['Accept' => 'application/json'])->json('data.url');
        $html = $this->bot($path)->getContent();
        $this->assertSame($og, $this->meta($html, 'property', 'og:image'));
        $this->assertSame($og, $this->meta($html, 'name', 'twitter:image'));

        $fav = $this->post(self::ADMIN_API.'/settings/asset', ['type' => 'favicon', 'file' => UploadedFile::fake()->image('f.png', 64, 64)], ['Accept' => 'application/json'])->json('data.url');
        $this->assertStringContainsString('<link rel="icon" href="'.$fav.'">', $this->bot($path)->getContent());
    }

    // ─── Indexing switches ───────────────────────────────────────────

    public function test_indexing_can_be_switched_off_and_query_variants_are_noindex(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard(['slug' => 'web']);
        $post = $this->approvedPost($board, ['title' => 'Idea']);
        $path = "/roadmap/web/p/{$post->number}-idea";

        $this->assertSame('index,follow', $this->meta($this->bot($path)->getContent(), 'name', 'robots'));

        // Query-string variants (filters, search, tracking) are noindex but canonical stays clean.
        $variant = $this->bot('/roadmap/web?q=dark&sort=new')->assertOk()->getContent();
        $this->assertSame('noindex,follow', $this->meta($variant, 'name', 'robots'));
        $this->assertSame('https://app.test/roadmap/web', $this->canonical($variant));
        $this->assertSame('noindex,follow', $this->meta($this->bot($path.'?utm_source=x')->getContent(), 'name', 'robots'));

        $this->putJson(self::ADMIN_API.'/settings', ['scope' => 'global', 'data' => ['seo' => ['indexable' => false]]])->assertOk();
        foreach (['/roadmap', '/roadmap/web', '/roadmap/web/roadmap', $path, '/changelog'] as $p) {
            $this->assertSame('noindex,follow', $this->meta($this->bot($p)->getContent(), 'name', 'robots'), $p);
        }
    }

    // ─── Redirects ───────────────────────────────────────────────────

    public function test_stale_post_slug_and_bare_number_redirect_to_the_canonical_url(): void
    {
        $board = $this->makeBoard(['slug' => 'web']);
        $post = $this->approvedPost($board, ['title' => 'Real title']);
        $canonical = "https://app.test/roadmap/web/p/{$post->number}-real-title";

        $this->bot("/roadmap/web/p/{$post->number}-old-title")->assertStatus(301)->assertRedirect($canonical);
        $this->bot("/roadmap/web/p/{$post->number}")->assertStatus(301)->assertRedirect($canonical);
        $this->bot("/roadmap/web/p/{$post->number}-real-title")->assertOk();
    }

    public function test_merged_and_moved_posts_redirect_to_their_new_home(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard(['slug' => 'web']);
        $other = $this->makeBoard(['slug' => 'ios']);
        $source = $this->approvedPost($board, ['title' => 'Duplicate']);
        $target = $this->approvedPost($board, ['title' => 'Original']);
        $mover = $this->approvedPost($board, ['title' => 'Traveller']);

        $this->postJson(self::ADMIN_API."/posts/{$source->id}/merge", ['target_post_id' => $target->id])->assertOk();
        $this->bot("/roadmap/web/p/{$source->number}-duplicate")->assertStatus(301)
            ->assertRedirect("https://app.test/roadmap/web/p/{$target->number}-original");

        $number = $mover->number;
        $this->postJson(self::ADMIN_API."/posts/{$mover->id}/move", ['board_id' => $other->id])->assertOk();
        $moved = $mover->fresh();
        $this->bot("/roadmap/web/p/{$number}-traveller")->assertStatus(301)
            ->assertRedirect("https://app.test/roadmap/ios/p/{$moved->number}-traveller");
    }

    public function test_a_merge_into_a_hidden_target_is_a_404(): void
    {
        $board = $this->makeBoard(['slug' => 'web']);
        $target = $this->makePost($board, ['moderation_state' => 'rejected']);
        $source = $this->approvedPost($board, ['title' => 'Gone', 'merged_into_post_id' => $target->id]);

        $this->bot("/roadmap/web/p/{$source->number}-gone")->assertNotFound();
    }

    public function test_renamed_boards_and_changelog_entries_redirect(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard(['slug' => 'web-app']);
        $post = $this->approvedPost($board, ['title' => 'Idea']);
        $entry = ChangelogEntry::create(['title' => 'Release', 'slug' => 'release', 'label' => 'new', 'body_md' => 'x', 'body_html' => '<p>x</p>', 'status' => 'published', 'published_at' => now()->subDay()]);

        $this->patchJson(self::ADMIN_API."/boards/{$board->id}", ['slug' => 'website'])->assertOk();
        $this->patchJson(self::ADMIN_API."/changelog/{$entry->id}", ['slug' => 'release-2'])->assertOk();

        $this->bot('/roadmap/web-app')->assertStatus(301)->assertRedirect('https://app.test/roadmap/website');
        $this->bot('/roadmap/web-app/roadmap')->assertStatus(301)->assertRedirect('https://app.test/roadmap/website/roadmap');
        $this->bot("/roadmap/web-app/p/{$post->number}-idea")->assertStatus(301)->assertRedirect("https://app.test/roadmap/website/p/{$post->number}-idea");
        $this->bot('/roadmap/website')->assertOk();
        $this->bot('/changelog/release')->assertStatus(301)->assertRedirect('https://app.test/changelog/release-2');
        $this->bot('/changelog/release-2')->assertOk();
    }

    // ─── 404s ────────────────────────────────────────────────────────

    public function test_unknown_pending_rejected_spam_and_archived_content_is_a_noindex_404(): void
    {
        $board = $this->makeBoard(['slug' => 'web']);
        $archived = $this->makeBoard(['slug' => 'old', 'is_archived' => true]);
        $pending = $this->makePost($board, ['title' => 'Pending', 'moderation_state' => 'pending']);
        $rejected = $this->makePost($board, ['title' => 'Rejected', 'moderation_state' => 'rejected']);
        $spam = $this->makePost($board, ['title' => 'Spam', 'moderation_state' => 'spam']);
        $inArchived = $this->approvedPost($archived, ['title' => 'Hidden']);
        ChangelogEntry::create(['title' => 'Draft', 'slug' => 'draft', 'label' => 'new', 'status' => 'draft', 'body_html' => '<p>x</p>']);
        ChangelogEntry::create(['title' => 'Later', 'slug' => 'later', 'label' => 'new', 'status' => 'published', 'published_at' => now()->addDay(), 'body_html' => '<p>x</p>']);

        $paths = [
            '/roadmap/nope',
            '/roadmap/nope/roadmap',
            '/roadmap/old',
            '/roadmap/web/p/999-missing',
            "/roadmap/web/p/{$pending->number}-pending",
            "/roadmap/web/p/{$rejected->number}-rejected",
            "/roadmap/web/p/{$spam->number}-spam",
            "/roadmap/old/p/{$inArchived->number}-hidden",
            '/roadmap/web/p/abc',
            '/roadmap/web/p/-5',
            '/changelog/missing',
            '/changelog/draft',
            '/changelog/later',
        ];

        foreach ($paths as $path) {
            $res = $this->bot($path);
            $res->assertNotFound();
            $html = $res->getContent();
            $this->assertSame('noindex,follow', $this->meta($html, 'name', 'robots'), $path);
            $res->assertHeader('X-Robots-Tag', 'noindex');
            $this->assertStringContainsString('Page not found', $html);
            foreach (['Pending', 'Rejected', 'Spam', 'Hidden', 'Draft'] as $title) {
                $this->assertStringNotContainsString(">$title<", $html, "$path leaks $title");
            }
        }
    }

    // ─── Escaping ────────────────────────────────────────────────────

    public function test_hostile_visitor_text_is_escaped_everywhere(): void
    {
        $evil = self::HOSTILE;
        $board = $this->makeBoard(['slug' => 'web', 'name' => 'Board '.$evil, 'description' => 'Desc "><img src=x onerror=alert(1)>']);
        $tag = Tag::create(['board_id' => $board->id, 'name' => 'Tag '.$evil, 'slug' => 'evil-tag']);
        $post = $this->approvedPost($board, [
            'title' => $evil,
            'body' => '"><img src=x onerror=alert(1)> </script><script>alert(2)</script>',
            'author_name' => '<b onmouseover=alert(3)>Bob</b>',
            'slug' => 'evil',
        ]);
        $post->tags()->attach($tag->id);
        $this->comment($post->id, null, ['body' => 'Hi '.$evil.' <a href="javascript:alert(4)">x</a>', 'author_name' => $evil]);

        $pages = [
            '/roadmap/web',
            '/roadmap/web/roadmap',
            "/roadmap/web/p/{$post->number}-evil",
            '/roadmap',
        ];

        foreach ($pages as $page) {
            $html = $this->bot($page)->assertOk()->getContent();

            // Nothing of the payload survives as markup...
            $this->assertStringNotContainsString($evil, $html, $page);
            $this->assertStringNotContainsString('<img src=x', $html, $page);
            $this->assertStringNotContainsString('<b onmouseover', $html, $page);
            $this->assertStringNotContainsString('<a href="javascript', $html, $page);
            $this->assertStringNotContainsString('<script>alert', $html, $page);
            // ...only our own scripts exist: one per JSON-LD block plus the redirect.
            $this->assertSame(count($this->jsonLd($html)) + 1, substr_count($html, '<script'), $page);
        }

        $html = $this->bot("/roadmap/web/p/{$post->number}-evil")->getContent();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'shown as text');
        $this->assertSame($evil.' | PNE Roadmap', $this->title($html), 'title is text, decoded by the browser');
        $this->assertSame($evil, $this->ldOfType($html, 'DiscussionForumPosting')['headline'], 'JSON-LD round-trips the raw text safely');
        $this->assertStringContainsString('&lt;b onmouseover=alert(3)&gt;Bob&lt;/b&gt;', $html);
    }

    public function test_hostile_changelog_content_and_site_settings_are_escaped(): void
    {
        $this->asAdmin();
        $evil = self::HOSTILE;
        $this->putJson(self::ADMIN_API.'/settings', ['scope' => 'global', 'data' => [
            'site' => ['name' => 'Site '.$evil, 'hero_title' => $evil, 'hero_subtitle' => '"><svg onload=alert(1)>'],
            'seo' => ['title_suffix' => '| '.$evil, 'meta_description' => '"><script>alert(1)</script>'],
        ]])->assertOk();
        ChangelogEntry::create([
            'title' => $evil, 'slug' => 'evil', 'label' => 'new', 'summary' => '"><svg onload=alert(1)>',
            'body_md' => 'x', 'body_html' => '<p>ok</p>', 'status' => 'published', 'published_at' => now()->subHour(),
        ]);
        $this->makeBoard(['slug' => 'web']);

        foreach (['/roadmap', '/changelog', '/changelog/evil'] as $path) {
            $html = $this->bot($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert', $html, $path);
            $this->assertStringNotContainsString('<svg onload', $html, $path);
            $this->assertSame(count($this->jsonLd($html)) + 1, substr_count($html, '<script'), $path);
        }
    }

    // ─── Changelog pages ─────────────────────────────────────────────

    public function test_changelog_index_and_entry_pages(): void
    {
        $board = $this->makeBoard(['slug' => 'web', 'name' => 'Web App']);
        $live = ChangelogEntry::create([
            'board_id' => $board->id, 'title' => 'Dark mode shipped', 'slug' => 'dark-mode-shipped', 'label' => 'new', 'summary' => 'It is here.',
            'body_md' => '**x**', 'body_html' => '<p><strong>Now</strong> available.</p>', 'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        ChangelogEntry::create(['title' => 'Draft entry', 'slug' => 'draft-entry', 'label' => 'fixed', 'status' => 'draft', 'body_html' => '<p>x</p>']);
        ChangelogEntry::create(['title' => 'Scheduled entry', 'slug' => 'scheduled-entry', 'label' => 'fixed', 'status' => 'published', 'published_at' => now()->addDay(), 'body_html' => '<p>x</p>']);

        $index = $this->bot('/changelog')->assertOk()->getContent();
        $this->assertSame('Changelog | PNE Roadmap', $this->title($index));
        $this->assertSame('https://app.test/changelog', $this->canonical($index));
        $this->assertStringContainsString('Dark mode shipped', $index);
        $this->assertStringNotContainsString('Draft entry', $index);
        $this->assertStringNotContainsString('Scheduled entry', $index);
        $this->assertStringContainsString('href="https://app.test/changelog/dark-mode-shipped"', $index);
        $this->assertStringContainsString('type="application/rss+xml"', $index);
        $this->assertStringContainsString('href="https://app.test/changelog/feed.xml"', $index);

        $res = $this->bot('/changelog/'.$live->slug)->assertOk();
        $html = $res->getContent();
        $this->assertSame('Dark mode shipped | PNE Roadmap', $this->title($html));
        $this->assertSame('It is here.', $this->meta($html, 'name', 'description'));
        $this->assertSame('https://app.test/changelog/dark-mode-shipped', $this->canonical($html));
        $this->assertSame('article', $this->meta($html, 'property', 'og:type'));
        $this->assertStringContainsString('<p><strong>Now</strong> available.</p>', $html);

        $post = $this->ldOfType($html, 'BlogPosting');
        $this->assertSame('Dark mode shipped', $post['headline']);
        $this->assertSame('https://app.test/changelog/dark-mode-shipped', $post['url']);
        $this->assertSame('PNE Team', $post['author']['name']);
        $this->assertSame(['Roadmap', 'Changelog', 'Dark mode shipped'], array_column($this->ldOfType($html, 'BreadcrumbList')['itemListElement'], 'name'));

        $this->putJsonAsAdmin(['features' => ['rss' => false]]);
        $this->assertStringNotContainsString('application/rss+xml', $this->bot('/changelog')->getContent());
    }

    private function putJsonAsAdmin(array $data): void
    {
        $this->asAdmin();
        $this->putJson(self::ADMIN_API.'/settings', ['scope' => 'global', 'data' => $data])->assertOk();
    }

    // ─── Sitemap ─────────────────────────────────────────────────────

    public function test_sitemap_lists_only_approved_public_content(): void
    {
        $web = $this->makeBoard(['slug' => 'web']);
        $archived = $this->makeBoard(['slug' => 'old', 'is_archived' => true]);
        $good = $this->approvedPost($web, ['title' => 'Good post', 'last_activity_at' => '2026-01-02 03:04:05']);
        $pending = $this->makePost($web, ['title' => 'Pending post', 'moderation_state' => 'pending']);
        $rejected = $this->makePost($web, ['title' => 'Rejected post', 'moderation_state' => 'rejected']);
        $spam = $this->makePost($web, ['title' => 'Spam post', 'moderation_state' => 'spam']);
        $merged = $this->approvedPost($web, ['title' => 'Merged post', 'merged_into_post_id' => $good->id]);
        $hidden = $this->approvedPost($archived, ['title' => 'Archived board post']);
        $live = ChangelogEntry::create(['title' => 'Live', 'slug' => 'live-entry', 'label' => 'new', 'status' => 'published', 'published_at' => now()->subDay(), 'body_html' => '<p>x</p>']);
        ChangelogEntry::create(['title' => 'Draft', 'slug' => 'draft-entry', 'label' => 'new', 'status' => 'draft', 'body_html' => '<p>x</p>']);
        ChangelogEntry::create(['title' => 'Later', 'slug' => 'later-entry', 'label' => 'new', 'status' => 'published', 'published_at' => now()->addDay(), 'body_html' => '<p>x</p>']);

        $res = $this->get(self::SEO.'/sitemap.xml')->assertOk();
        $res->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($res->getContent());
        $this->assertNotFalse($xml, 'sitemap is well-formed XML');
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locs = array_map('strval', $xml->xpath('//s:url/s:loc'));

        $this->assertContains('https://app.test/roadmap', $locs);
        $this->assertContains('https://app.test/roadmap/web', $locs);
        $this->assertContains('https://app.test/roadmap/web/roadmap', $locs);
        $this->assertContains("https://app.test/roadmap/web/p/{$good->number}-good-post", $locs);
        $this->assertContains('https://app.test/changelog', $locs);
        $this->assertContains('https://app.test/changelog/live-entry', $locs);

        $this->assertSame([], array_filter($locs, fn ($l) => str_contains($l, '/roadmap/old')), 'archived boards and their posts are excluded');
        $this->assertNotContains("https://app.test/roadmap/web/p/{$pending->number}-pending-post", $locs);
        $this->assertNotContains("https://app.test/roadmap/web/p/{$rejected->number}-rejected-post", $locs);
        $this->assertNotContains("https://app.test/roadmap/web/p/{$spam->number}-spam-post", $locs);
        $this->assertNotContains("https://app.test/roadmap/web/p/{$merged->number}-merged-post", $locs);
        $this->assertNotContains('https://app.test/roadmap/old', $locs);
        $this->assertNotContains('https://app.test/changelog/draft-entry', $locs);
        $this->assertNotContains('https://app.test/changelog/later-entry', $locs);
        $this->assertCount(count(array_unique($locs)), $locs, 'no duplicates');
        $this->assertSame([], array_filter($locs, fn ($l) => ! str_starts_with($l, 'https://app.test/')), 'canonical SPA URLs only');

        $lastmod = (string) $xml->xpath("//s:url[s:loc='https://app.test/roadmap/web/p/{$good->number}-good-post']/s:lastmod")[0];
        $this->assertSame('2026-01-02T03:04:05Z', $lastmod);
        $this->assertSame((string) $live->updated_at->toIso8601ZuluString(), (string) $xml->xpath("//s:url[s:loc='https://app.test/changelog/live-entry']/s:lastmod")[0]);
    }

    public function test_sitemap_is_cached_for_an_hour_and_can_be_flushed(): void
    {
        $board = $this->makeBoard(['slug' => 'web']);
        $first = $this->approvedPost($board, ['title' => 'First']);

        $one = $this->get(self::SEO.'/sitemap.xml')->assertOk();
        $one->assertHeader('Cache-Control', 'max-age=3600, public');
        $this->assertStringContainsString('-first', $one->getContent());

        $this->approvedPost($board, ['title' => 'Second']);
        $this->assertStringNotContainsString('-second', $this->get(self::SEO.'/sitemap.xml')->getContent(), 'cached');

        app(SitemapService::class)->forget();
        $this->assertStringContainsString('-second', $this->get(self::SEO.'/sitemap.xml')->getContent());
        $this->assertLessThanOrEqual(50000, SitemapService::MAX_URLS);
    }

    public function test_sitemap_is_empty_when_the_site_is_not_indexable(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard(['slug' => 'web']);
        $this->approvedPost($board, ['title' => 'Post']);
        $this->putJson(self::ADMIN_API.'/settings', ['scope' => 'global', 'data' => ['seo' => ['indexable' => false]]])->assertOk();
        app(SitemapService::class)->forget();

        $xml = simplexml_load_string($this->get(self::SEO.'/sitemap.xml')->getContent());

        $this->assertNotFalse($xml);
        $this->assertCount(0, $xml->children());
    }

    // ─── RSS ─────────────────────────────────────────────────────────

    public function test_rss_feed_contains_live_entries_only(): void
    {
        $board = $this->makeBoard(['slug' => 'web']);
        foreach (range(1, 22) as $i) {
            ChangelogEntry::create([
                'title' => "Entry $i", 'slug' => "entry-$i", 'label' => $i % 2 ? 'new' : 'fixed', 'summary' => "Summary $i",
                'body_md' => 'x', 'body_html' => "<p>Body $i</p>", 'status' => 'published', 'published_at' => now()->subHours(100 - $i),
            ]);
        }
        ChangelogEntry::create(['title' => 'Draft', 'slug' => 'draft', 'label' => 'new', 'status' => 'draft', 'body_html' => '<p>x</p>']);
        ChangelogEntry::create(['title' => 'Scheduled', 'slug' => 'scheduled', 'label' => 'new', 'status' => 'published', 'published_at' => now()->addDay(), 'body_html' => '<p>x</p>']);

        $xml = simplexml_load_string(app(RssService::class)->changelogFeed());
        $this->assertNotFalse($xml);
        $this->assertSame('2.0', (string) $xml['version']);
        $items = $xml->channel->item;
        $this->assertCount(20, $items);
        $this->assertSame('Entry 22', (string) $items[0]->title, 'newest first');
        $this->assertSame('https://app.test/changelog/entry-22', (string) $items[0]->link);
        $this->assertSame('https://app.test/changelog/entry-22', (string) $items[0]->guid);
        $this->assertNotEmpty((string) $items[0]->pubDate);
        $titles = array_map(fn ($i) => (string) $i->title, iterator_to_array($items, false));
        $this->assertNotContains('Draft', $titles);
        $this->assertNotContains('Scheduled', $titles);
        $this->assertSame('https://app.test/changelog', (string) $xml->channel->link);

        // The public endpoint serves the same feed.
        $res = $this->get('/api/public/roadmap/changelog/feed.xml')->assertOk();
        $this->assertStringContainsString('application/rss+xml', $res->headers->get('Content-Type'));
        $this->assertNotFalse(simplexml_load_string($res->getContent()));
    }

    public function test_rss_escapes_hostile_titles_and_cdata_terminators(): void
    {
        ChangelogEntry::create([
            'title' => self::HOSTILE.' & <b>', 'slug' => 'evil', 'label' => 'new', 'summary' => null,
            'body_md' => 'x', 'body_html' => '<p>a ]]> b</p>', 'status' => 'published', 'published_at' => now()->subHour(),
        ]);

        $raw = app(RssService::class)->changelogFeed();
        $xml = simplexml_load_string($raw);

        $this->assertNotFalse($xml, 'feed stays well-formed');
        $this->assertStringNotContainsString(self::HOSTILE, $raw);
        $this->assertSame(self::HOSTILE.' & <b>', (string) $xml->channel->item[0]->title);
    }

    // ─── Misc ────────────────────────────────────────────────────────

    public function test_shell_routes_are_not_ip_throttled_and_need_no_token(): void
    {
        $this->makeBoard(['slug' => 'web']);

        foreach (range(1, 30) as $i) {
            $this->bot('/roadmap/web')->assertOk();
        }
    }

    public function test_an_unexpected_error_yields_a_generic_500_without_details(): void
    {
        config(['app.debug' => true]);
        $this->makeBoard(['slug' => 'web']);
        $mock = \Mockery::mock(SeoService::class);
        $mock->shouldReceive('resolveBoard')->andThrow(new \RuntimeException('SQLSTATE secret'));
        $this->app->instance(SeoService::class, $mock);

        $res = $this->bot('/roadmap/web')->assertStatus(500);

        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        $this->assertStringNotContainsString('secret', $res->getContent());
    }

    public function test_slug_redirect_rows_for_unknown_targets_do_not_break_the_shell(): void
    {
        $this->makeBoard(['slug' => 'web']);
        SlugRedirect::create(['kind' => 'board', 'old_key' => 'ghost', 'target_id' => 999999, 'created_at' => now()]);
        SlugRedirect::create(['kind' => 'changelog', 'old_key' => 'ghost', 'target_id' => 999999, 'created_at' => now()]);
        SlugRedirect::create(['kind' => 'post', 'old_key' => '1:77', 'target_id' => 999999, 'created_at' => now()]);

        $this->bot('/roadmap/ghost')->assertNotFound();
        $this->bot('/changelog/ghost')->assertNotFound();
        $this->bot('/roadmap/web/p/77-x')->assertNotFound();
    }
}
