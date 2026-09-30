<?php

namespace Tests\Feature\Roadmap;

use App\Services\Roadmap\MarkdownRenderer;

class MarkdownTest extends RoadmapTestCase
{
    use AdminApiTrait;

    private function render(string $md): string
    {
        return app(MarkdownRenderer::class)->render($md);
    }

    public function test_script_blocks_and_inline_html_are_stripped(): void
    {
        $html = $this->render("Hello\n\n<script>alert(1)</script>\n\nand <img src=x onerror=alert(2)> and <b onclick=\"x()\">bold</b>");

        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $html);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b ', $html);
        $this->assertStringContainsString('Hello', $html);
    }

    public function test_unsafe_link_schemes_lose_their_href(): void
    {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==', 'vbscript:msgbox(1)'] as $url) {
            $html = $this->render("[click me]($url)");

            $this->assertStringNotContainsStringIgnoringCase('javascript:', $html, $url);
            $this->assertStringNotContainsStringIgnoringCase('data:text', $html, $url);
            $this->assertStringNotContainsStringIgnoringCase('vbscript:', $html, $url);
            $this->assertStringContainsString('click me', $html);
        }

        // Autolinks and reference links too (the visible text stays, but there is no href).
        $this->assertStringNotContainsString('href', $this->render('<javascript:alert(1)>'));
        $this->assertStringNotContainsString('href', $this->render("[x][1]\n\n[1]: javascript:alert(1)"));
    }

    public function test_external_links_get_rel_and_target(): void
    {
        $html = $this->render('[docs](https://example.com/docs?a=1&b=2)');

        $this->assertStringContainsString('href="https://example.com/docs?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('rel="nofollow noopener noreferrer"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function test_links_to_our_own_site_are_not_marked_external(): void
    {
        $html = $this->render('[roadmap](https://app.test/roadmap/web)');

        $this->assertStringContainsString('href="https://app.test/roadmap/web"', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertStringNotContainsString('nofollow', $html);
    }

    public function test_only_https_images_survive(): void
    {
        $ok = $this->render('![logo](https://example.com/a.png)');
        $this->assertStringContainsString('<img src="https://example.com/a.png"', $ok);

        foreach (['http://example.com/a.png', 'data:image/png;base64,AAAA', 'javascript:alert(1)', '//example.com/a.png', '/relative.png'] as $url) {
            $html = $this->render("![alt text]($url)");
            $this->assertStringNotContainsString('<img', $html, $url);
            $this->assertStringNotContainsString('src=', $html, $url);
            $this->assertStringContainsString('alt text', $html);
        }
    }

    public function test_tables_and_strikethrough_render(): void
    {
        $html = $this->render("| a | b |\n|---|---|\n| 1 | 2 |\n\n~~gone~~");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<del>gone</del>', $html);
    }

    public function test_input_is_capped_and_empty_input_gives_empty_html(): void
    {
        $this->assertSame('', $this->render(''));
        $this->assertSame('', $this->render("   \n  "));

        $html = $this->render(str_repeat('a', MarkdownRenderer::MAX_BYTES).'TAILMARKER');
        $this->assertStringNotContainsString('TAILMARKER', $html);
    }

    public function test_nesting_is_bounded(): void
    {
        $html = $this->render(str_repeat('> ', 60).'deep');

        $this->assertLessThanOrEqual(21, substr_count($html, '<blockquote>'));
    }

    public function test_preview_endpoint_uses_the_same_renderer(): void
    {
        $this->asAdmin();

        $payload = ['md' => "**hi** <script>alert(1)</script>\n\n[x](javascript:alert(1))"];
        $html = $this->postJson(self::ADMIN_API.'/markdown/preview', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.html');

        $this->assertStringContainsString('<strong>hi</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        $this->postJson(self::ADMIN_API.'/markdown/preview', ['md' => str_repeat('a', 20001)])->assertStatus(422);
    }

    public function test_official_response_and_changelog_store_sanitised_html(): void
    {
        $this->asAdmin();
        $board = $this->makeBoard();
        $post = $this->approvedPost($board);

        $this->putJson(self::ADMIN_API."/posts/{$post->id}/response", ['body_md' => "Thanks!\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1)) [ok](https://example.com)"])
            ->assertOk();

        $post->refresh();
        $this->assertStringContainsString('<script>alert(1)</script>', (string) $post->response_md, 'raw markdown is kept as typed');
        $this->assertStringNotContainsString('<script', $post->response_html);
        $this->assertStringNotContainsString('javascript:', $post->response_html);
        $this->assertStringContainsString('rel="nofollow noopener noreferrer"', $post->response_html);

        $entry = $this->postJson(self::ADMIN_API.'/changelog', [
            'title' => 'Shipped',
            'label' => 'new',
            'body_md' => "# Big\n\n<img src=x onerror=alert(1)>",
        ])->assertCreated()->json('data');

        $this->assertStringNotContainsString('onerror', $entry['body_html']);
        $this->assertStringContainsString('<h1>Big</h1>', $entry['body_html']);
    }
}
