<?php

namespace App\Http\Controllers\Roadmap\Seo;

use App\Http\Controllers\Controller;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\SlugRedirect;
use App\Services\Roadmap\SeoService;
use App\Services\Roadmap\SitemapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * HTML preview shell for crawlers and link scrapers (reached through the frontend nginx).
 * Real title / meta / canonical / OG / JSON-LD plus readable content; humans are sent on to
 * the SPA by a small script. Every value is escaped by the Blade views.
 *
 * Redirects (301) point at the canonical SPA URL, never at /api/seo/..., because nginx proxies
 * the response as-is to the crawler.
 */
class SeoController extends Controller
{
    private const CACHE = 'public, max-age=300';

    public function __construct(
        private SeoService $seo,
        private SitemapService $sitemap,
    ) {}

    public function sitemap(Request $request): Response
    {
        try {
            return $this->cached($request, response($this->sitemap->xml(), 200, [
                'Content-Type' => 'application/xml; charset=UTF-8',
            ]), 3600);
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    public function home(Request $request): Response|RedirectResponse
    {
        return $this->guard(fn () => $this->render($request, $this->seo->home()));
    }

    public function board(Request $request, string $board): Response|RedirectResponse
    {
        return $this->guard(function () use ($request, $board) {
            $resolved = $this->seo->resolveBoard($board);
            if ($resolved['redirect']) {
                return $this->moved($this->seo->boardPath($resolved['redirect']));
            }

            return $resolved['board'] ? $this->render($request, $this->seo->board($resolved['board'])) : $this->notFound();
        });
    }

    public function boardRoadmap(Request $request, string $board): Response|RedirectResponse
    {
        return $this->guard(function () use ($request, $board) {
            $resolved = $this->seo->resolveBoard($board);
            if ($resolved['redirect']) {
                return $this->moved($this->seo->boardPath($resolved['redirect']).'/roadmap');
            }

            return $resolved['board'] ? $this->render($request, $this->seo->board($resolved['board'], true)) : $this->notFound();
        });
    }

    public function post(Request $request, string $board, string $numberSlug): Response|RedirectResponse
    {
        return $this->guard(function () use ($request, $board, $numberSlug) {
            $resolved = $this->seo->resolveBoard($board);
            if ($resolved['redirect']) {
                return $this->moved($this->seo->boardPath($resolved['redirect']).'/p/'.$numberSlug);
            }
            if (! $resolved['board']) {
                return $this->notFound();
            }

            $result = $this->seo->resolvePost($resolved['board'], $numberSlug);
            if ($result['redirect']) {
                return $this->moved($result['redirect']);
            }

            return $result['post'] ? $this->render($request, $this->seo->post($result['post'])) : $this->notFound();
        });
    }

    public function changelogIndex(Request $request): Response|RedirectResponse
    {
        return $this->guard(fn () => $this->render($request, $this->seo->changelogIndex()));
    }

    public function changelogEntry(Request $request, string $slug): Response|RedirectResponse
    {
        return $this->guard(function () use ($request, $slug) {
            $entry = ChangelogEntry::query()->live()->where('slug', $slug)->first();

            if (! $entry) {
                $redirect = SlugRedirect::query()->where('kind', 'changelog')->where('old_key', $slug)->first();
                $moved = $redirect ? ChangelogEntry::query()->live()->find($redirect->target_id) : null;

                return $moved ? $this->moved('/changelog/'.$moved->slug) : $this->notFound();
            }

            return $this->render($request, $this->seo->changelogEntry($entry));
        });
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /** @param array<string,mixed> $page */
    private function render(Request $request, array $page, int $status = 200): Response
    {
        $html = view('roadmap.seo.'.$page['view'], ['page' => $page])->render();
        $response = response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);

        return $status === 200 ? $this->cached($request, $response) : $response->header('Cache-Control', 'public, max-age=60');
    }

    private function cached(Request $request, Response $response, int $maxAge = 300): Response
    {
        $response->setEtag(md5((string) $response->getContent()));
        $response->headers->set('Cache-Control', $maxAge === 300 ? self::CACHE : 'public, max-age='.$maxAge);
        $response->isNotModified($request);

        return $response;
    }

    private function notFound(): Response
    {
        $response = $this->render(request(), $this->seo->notFound(), 404);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    private function moved(string $spaPath): RedirectResponse
    {
        return redirect()->away($this->seo->url($spaPath), 301)->header('Cache-Control', 'public, max-age=3600');
    }

    /** Never leak internals to crawlers: log and answer with the generic 404-style page. */
    private function guard(callable $callback): Response|RedirectResponse
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    private function failed(\Throwable $e): Response
    {
        Log::error('Roadmap SEO shell failed', ['exception' => $e]);

        return response('Something went wrong.', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
