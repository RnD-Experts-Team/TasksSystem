<?php

namespace Tests\Feature\Roadmap;

use App\Services\Roadmap\BoardService;
use App\Services\Roadmap\PostService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GuardTest extends RoadmapTestCase
{
    private function cacheDirective($response, string $name): ?string
    {
        $value = $response->baseResponse->headers->getCacheControlDirective($name);

        return $value === null ? null : (string) $value;
    }

    private function assertGeneric500($response): void
    {
        $response->assertStatus(500)->assertExactJson([
            'success' => false,
            'data' => null,
            'message' => 'Something went wrong.',
        ]);

        $body = $response->getContent();
        foreach (['super-secret', 'RuntimeException', 'SQLSTATE', 'GuardTest', 'trace', 'vendor/', '/Volumes/'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_a_throwing_dependency_returns_a_generic_500_even_with_debug_on(): void
    {
        config(['app.debug' => true]);
        Log::spy();

        $this->app->bind(BoardService::class, fn () => new class extends BoardService
        {
            public function summaries(): Collection
            {
                throw new RuntimeException('super-secret database password leaked SQLSTATE[HY000]');
            }
        });

        $this->assertGeneric500($this->getJson(self::API.'/boards'));
    }

    public function test_a_throwing_write_path_is_also_generic(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $board = $this->makeBoard();
        ['token' => $token] = $this->issueVisitor();

        $this->app->bind(PostService::class, fn () => throw new RuntimeException('super-secret'));

        $this->assertGeneric500($this->withVisitor($token)->postJson(self::API.'/boards/'.$board->slug.'/posts', [
            'title' => 'A perfectly fine title', 'form_token' => 'x',
        ]));
    }

    public function test_a_query_exception_does_not_leak_sql(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $board = $this->makeBoard();
        $this->approvedPost($board);
        \Schema::drop('roadmap_votes');

        $this->assertGeneric500($this->getJson(self::API.'/boards/'.$board->slug.'/posts?sort=trending'));
    }

    public function test_the_exception_is_reported_for_operators(): void
    {
        config(['app.debug' => false]);
        Log::spy();

        $this->app->bind(BoardService::class, fn () => new class extends BoardService
        {
            public function summaries(): Collection
            {
                throw new RuntimeException('boom');
            }
        });

        $this->getJson(self::API.'/boards')->assertStatus(500);
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_not_found_validation_and_method_errors_use_clean_envelopes(): void
    {
        config(['app.debug' => true]);
        $board = $this->makeBoard();

        $this->getJson(self::API.'/boards/'.$board->slug.'/posts/999')
            ->assertStatus(404)->assertExactJson(['success' => false, 'data' => null, 'message' => 'Not found.', 'code' => 'not_found']);

        $this->getJson(self::API.'/boards/'.$board->slug.'/posts?per_page=500&sort=bogus')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page', 'sort'])->assertJsonStructure(['message', 'errors']);
    }

    public function test_json_is_forced_even_without_an_accept_header(): void
    {
        $board = $this->makeBoard();

        $response = $this->call('GET', self::API.'/boards/'.$board->slug.'/posts', ['per_page' => 999], [], [], ['HTTP_ACCEPT' => 'text/html']);

        $response->assertStatus(422);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_cache_headers_and_etag_revalidation(): void
    {
        $board = $this->makeBoard();
        $this->approvedPost($board);

        $first = $this->getJson(self::API.'/boards/'.$board->slug.'/posts')->assertOk();
        $this->assertSame('15', $this->cacheDirective($first, 'max-age'));
        $this->assertSame('60', $this->cacheDirective($first, 'stale-while-revalidate'));
        $this->assertTrue($first->baseResponse->headers->hasCacheControlDirective('public'));
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeader('If-None-Match', $etag)->getJson(self::API.'/boards/'.$board->slug.'/posts')->assertStatus(304);
        $this->withHeader('If-None-Match', '"other"')->getJson(self::API.'/boards/'.$board->slug.'/posts')->assertOk();

        $boards = $this->getJson(self::API.'/boards')->assertOk();
        $this->assertSame('60', $this->cacheDirective($boards, 'max-age'));
        $this->assertSame('240', $this->cacheDirective($boards, 'stale-while-revalidate'));

        // writes and per-visitor state are never cacheable
        ['token' => $token] = $this->issueVisitor();
        $this->assertStringContainsString('no-store', (string) $this->withVisitor($token)->getJson(self::API.'/me/state')->headers->get('Cache-Control'));
        $post = $this->postJson(self::API.'/visitor');
        $this->assertStringContainsString('no-store', (string) $post->headers->get('Cache-Control'));
        // similar is deliberately not cached (search-as-you-type)
        $this->assertStringNotContainsString('public', (string) $this->getJson(self::API.'/boards/'.$board->slug.'/posts/similar?q=abc')->headers->get('Cache-Control'));
    }
}
