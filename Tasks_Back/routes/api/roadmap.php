<?php

// routes/api/roadmap.php
//
// Public Roadmap & Feedback module.
//
//   /api/public/roadmap/...   unauthenticated, throttled, generic-error guarded (anonymous visitors)
//   /api/roadmap/admin/...    Sanctum + role_or_permission:admin|<permission> (staff console)
//   /api/seo/...              HTML preview shell for crawlers / link scrapers (reached via nginx)
//
// Middleware notes:
//  - Throttling uses our own RoadmapThrottle middleware (NOT RateLimiter::for) so it keeps
//    working under `php artisan route:cache`.
//  - Middleware classes are referenced by class name with parameters, so no alias needs to be
//    registered in bootstrap/app.php.
//  - ResolveVisitor must run BEFORE RoadmapThrottle (per-visitor buckets need the visitor).
//  - Admin routes use role_or_permission so the "admin" role passes even before the
//    RoadmapPermissionSeeder has been run.

use App\Http\Controllers\Roadmap\Admin;
use App\Http\Controllers\Roadmap\PublicApi;
use App\Http\Controllers\Roadmap\Seo\SeoController;
use App\Http\Middleware\Roadmap\ForceJson;
use App\Http\Middleware\Roadmap\PublicApiGuard;
use App\Http\Middleware\Roadmap\PublicCache;
use App\Http\Middleware\Roadmap\ResolveVisitor;
use App\Http\Middleware\Roadmap\RoadmapThrottle;
use Illuminate\Support\Facades\Route;

$slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

// ════════════════════════════════════════════════════════════════════
// PUBLIC API  (anonymous visitors)
// ════════════════════════════════════════════════════════════════════
Route::prefix('public/roadmap')
    ->middleware([ForceJson::class, PublicApiGuard::class])
    ->group(function () use ($slug) {
        $visitor = fn (string $mode) => ResolveVisitor::class.':'.$mode;
        $throttle = fn (string $name) => RoadmapThrottle::class.':'.$name;
        $cache = fn (int $seconds) => PublicCache::class.':'.$seconds;

        // ── Visitor identity ────────────────────────────────────────
        Route::post('/visitor', [PublicApi\VisitorController::class, 'issue'])
            ->middleware([$visitor('optional'), $throttle('issue')]);

        // ── Site / boards (cacheable, visitor-agnostic) ─────────────
        Route::get('/config', [PublicApi\ConfigController::class, 'show'])
            ->middleware([$throttle('read'), $cache(60)]);
        Route::get('/boards', [PublicApi\BoardController::class, 'index'])
            ->middleware([$throttle('read'), $cache(60)]);
        Route::get('/boards/{board}', [PublicApi\BoardController::class, 'show'])
            ->where('board', $slug)->middleware([$throttle('read'), $cache(60)]);

        // ── Posts ────────────────────────────────────────────────────
        Route::get('/boards/{board}/posts', [PublicApi\PostController::class, 'index'])
            ->where('board', $slug)->middleware([$throttle('read'), $cache(15)]);
        Route::get('/boards/{board}/posts/similar', [PublicApi\PostController::class, 'similar'])
            ->where('board', $slug)->middleware([$throttle('suggest')]);
        Route::get('/boards/{board}/posts/{number}', [PublicApi\PostController::class, 'show'])
            ->where('board', $slug)->whereNumber('number')
            ->middleware([$visitor('optional'), $throttle('read'), $cache(15)]);
        Route::post('/boards/{board}/posts', [PublicApi\PostController::class, 'store'])
            ->where('board', $slug)->middleware([$visitor('required'), $throttle('post-submit')]);

        // ── Votes ────────────────────────────────────────────────────
        Route::post('/boards/{board}/posts/{number}/vote', [PublicApi\VoteController::class, 'set'])
            ->where('board', $slug)->whereNumber('number')
            ->middleware([$visitor('required'), $throttle('vote')]);

        // ── Comments ─────────────────────────────────────────────────
        Route::get('/boards/{board}/posts/{number}/comments', [PublicApi\CommentController::class, 'index'])
            ->where('board', $slug)->whereNumber('number')->middleware([$throttle('read'), $cache(15)]);
        Route::post('/boards/{board}/posts/{number}/comments', [PublicApi\CommentController::class, 'store'])
            ->where('board', $slug)->whereNumber('number')
            ->middleware([$visitor('required'), $throttle('comment')]);

        // ── Roadmap columns ──────────────────────────────────────────
        Route::get('/boards/{board}/roadmap', [PublicApi\RoadmapController::class, 'show'])
            ->where('board', $slug)->middleware([$throttle('read'), $cache(15)]);

        // ── Changelog ────────────────────────────────────────────────
        Route::get('/changelog', [PublicApi\ChangelogController::class, 'index'])
            ->middleware([$throttle('read'), $cache(15)]);
        Route::get('/changelog/feed.xml', [PublicApi\ChangelogFeedController::class, 'rss'])
            ->middleware([$throttle('read')]);
        Route::get('/changelog/{slug}', [PublicApi\ChangelogController::class, 'show'])
            ->where('slug', $slug)->middleware([$throttle('read'), $cache(15)]);

        // ── Anti-abuse form tokens ───────────────────────────────────
        Route::post('/forms/{kind}/start', [PublicApi\FormController::class, 'start'])
            ->where('kind', 'post|comment')->middleware([$visitor('required'), $throttle('form-start')]);

        // ── Per-visitor state (never cached) ─────────────────────────
        Route::get('/me/state', [PublicApi\MeController::class, 'state'])
            ->middleware([$visitor('optional'), $throttle('read')]);
    });

// ════════════════════════════════════════════════════════════════════
// SEO PREVIEW SHELL  (HTML for crawlers; reached through the frontend nginx)
// Not IP-throttled: every request comes from our own nginx, so they rely on caching.
// ════════════════════════════════════════════════════════════════════
Route::prefix('seo')->group(function () use ($slug) {
    Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
    Route::get('/roadmap', [SeoController::class, 'home']);
    Route::get('/roadmap/{board}', [SeoController::class, 'board'])->where('board', $slug);
    Route::get('/roadmap/{board}/roadmap', [SeoController::class, 'boardRoadmap'])->where('board', $slug);
    Route::get('/roadmap/{board}/p/{numberSlug}', [SeoController::class, 'post'])->where('board', $slug);
    Route::get('/changelog', [SeoController::class, 'changelogIndex']);
    Route::get('/changelog/{slug}', [SeoController::class, 'changelogEntry'])->where('slug', $slug);
});

// ════════════════════════════════════════════════════════════════════
// ADMIN API  (staff console)
// ════════════════════════════════════════════════════════════════════
Route::prefix('roadmap/admin')->middleware(['auth:sanctum'])->group(function () {
    // ── manage roadmap: boards, statuses, tags, posts, roadmap board ──
    Route::middleware('role_or_permission:admin|manage roadmap')->group(function () {
        // boards
        Route::get('/boards', [Admin\BoardController::class, 'index']);
        Route::post('/boards', [Admin\BoardController::class, 'store']);
        Route::post('/boards/reorder', [Admin\BoardController::class, 'reorder']);
        Route::get('/boards/{board}', [Admin\BoardController::class, 'show'])->whereNumber('board');
        Route::patch('/boards/{board}', [Admin\BoardController::class, 'update'])->whereNumber('board');
        Route::delete('/boards/{board}', [Admin\BoardController::class, 'destroy'])->whereNumber('board');

        // statuses
        Route::get('/boards/{board}/statuses', [Admin\StatusController::class, 'index'])->whereNumber('board');
        Route::post('/boards/{board}/statuses', [Admin\StatusController::class, 'store'])->whereNumber('board');
        Route::post('/boards/{board}/statuses/reorder', [Admin\StatusController::class, 'reorder'])->whereNumber('board');
        Route::post('/boards/{board}/statuses/{status}/make-default', [Admin\StatusController::class, 'makeDefault'])
            ->whereNumber('board')->whereNumber('status');
        Route::patch('/statuses/{status}', [Admin\StatusController::class, 'update'])->whereNumber('status');
        Route::delete('/statuses/{status}', [Admin\StatusController::class, 'destroy'])->whereNumber('status');

        // tags
        Route::get('/boards/{board}/tags', [Admin\TagController::class, 'index'])->whereNumber('board');
        Route::post('/boards/{board}/tags', [Admin\TagController::class, 'store'])->whereNumber('board');
        Route::post('/boards/{board}/tags/reorder', [Admin\TagController::class, 'reorder'])->whereNumber('board');
        Route::patch('/tags/{tag}', [Admin\TagController::class, 'update'])->whereNumber('tag');
        Route::delete('/tags/{tag}', [Admin\TagController::class, 'destroy'])->whereNumber('tag');

        // roadmap kanban
        Route::get('/boards/{board}/roadmap', [Admin\PostController::class, 'roadmap'])->whereNumber('board');
        Route::post('/boards/{board}/roadmap/move', [Admin\PostController::class, 'roadmapMove'])->whereNumber('board');

        // posts (write actions)
        Route::post('/posts', [Admin\PostController::class, 'store']);
        Route::patch('/posts/{post}', [Admin\PostController::class, 'update'])->whereNumber('post');
        Route::delete('/posts/{post}', [Admin\PostController::class, 'destroy'])->whereNumber('post');
        Route::post('/posts/{post}/status', [Admin\PostController::class, 'status'])->whereNumber('post');
        Route::put('/posts/{post}/response', [Admin\PostController::class, 'setResponse'])->whereNumber('post');
        Route::delete('/posts/{post}/response', [Admin\PostController::class, 'clearResponse'])->whereNumber('post');
        Route::post('/posts/{post}/pin', [Admin\PostController::class, 'pin'])->whereNumber('post');
        Route::post('/posts/{post}/merge/preview', [Admin\PostController::class, 'mergePreview'])->whereNumber('post');
        Route::post('/posts/{post}/merge', [Admin\PostController::class, 'merge'])->whereNumber('post');
        Route::post('/posts/{post}/move', [Admin\PostController::class, 'move'])->whereNumber('post');
        Route::put('/posts/{post}/tags', [Admin\PostController::class, 'syncTags'])->whereNumber('post');
    });

    // ── posts: read + moderate (manage OR moderate) ──────────────────
    Route::middleware('role_or_permission:admin|manage roadmap|moderate roadmap')->group(function () {
        Route::get('/posts', [Admin\PostController::class, 'index']);
        Route::get('/posts/{post}', [Admin\PostController::class, 'show'])->whereNumber('post');
        Route::post('/posts/bulk-moderate', [Admin\PostController::class, 'bulkModerate']);
        Route::post('/posts/{post}/moderate', [Admin\PostController::class, 'moderate'])->whereNumber('post');
        Route::get('/analytics/summary', [Admin\AnalyticsController::class, 'summary']);
    });

    // ── moderate roadmap: comments, visitors, abuse ──────────────────
    Route::middleware('role_or_permission:admin|moderate roadmap')->group(function () {
        Route::get('/comments', [Admin\CommentController::class, 'index']);
        Route::post('/comments/bulk-moderate', [Admin\CommentController::class, 'bulkModerate']);
        Route::post('/comments/{comment}/moderate', [Admin\CommentController::class, 'moderate'])->whereNumber('comment');
        Route::delete('/comments/{comment}', [Admin\CommentController::class, 'destroy'])->whereNumber('comment');
        Route::post('/posts/{post}/comments', [Admin\CommentController::class, 'reply'])->whereNumber('post');

        Route::get('/visitors', [Admin\VisitorController::class, 'index']);
        Route::get('/visitors/{visitor}', [Admin\VisitorController::class, 'show'])->where('visitor', '[0-9A-Za-z]{26}');
        Route::post('/visitors/{visitor}/ban', [Admin\VisitorController::class, 'ban'])->where('visitor', '[0-9A-Za-z]{26}');
        Route::post('/visitors/{visitor}/unban', [Admin\VisitorController::class, 'unban'])->where('visitor', '[0-9A-Za-z]{26}');

        Route::post('/abuse/bulk-remove', [Admin\AbuseController::class, 'bulkRemove']);
        Route::get('/abuse/events', [Admin\AbuseController::class, 'events']);
    });

    // ── manage changelog ─────────────────────────────────────────────
    Route::middleware('role_or_permission:admin|manage changelog')->group(function () {
        Route::get('/changelog', [Admin\ChangelogController::class, 'index']);
        Route::post('/changelog', [Admin\ChangelogController::class, 'store']);
        Route::get('/changelog/{entry}', [Admin\ChangelogController::class, 'show'])->whereNumber('entry');
        Route::patch('/changelog/{entry}', [Admin\ChangelogController::class, 'update'])->whereNumber('entry');
        Route::delete('/changelog/{entry}', [Admin\ChangelogController::class, 'destroy'])->whereNumber('entry');
        Route::post('/changelog/{entry}/publish', [Admin\ChangelogController::class, 'publish'])->whereNumber('entry');
        Route::post('/changelog/{entry}/unpublish', [Admin\ChangelogController::class, 'unpublish'])->whereNumber('entry');
        Route::put('/changelog/{entry}/posts', [Admin\ChangelogController::class, 'syncPosts'])->whereNumber('entry');
    });

    // ── manage roadmap settings: branding, uploads, limits ───────────
    Route::middleware('role_or_permission:admin|manage roadmap settings')->group(function () {
        Route::get('/settings', [Admin\SettingsController::class, 'show']);
        Route::put('/settings', [Admin\SettingsController::class, 'update']);
        Route::post('/settings/asset', [Admin\SettingsController::class, 'uploadAsset']);
        Route::delete('/settings/asset/{type}', [Admin\SettingsController::class, 'deleteAsset'])
            ->where('type', 'logo|logo_dark|favicon|og');
        Route::post('/settings/theme-preview', [Admin\SettingsController::class, 'themePreview']);
    });

    // ── markdown preview: any content editor ─────────────────────────
    Route::middleware('role_or_permission:admin|manage roadmap|manage changelog|manage roadmap settings')
        ->post('/markdown/preview', [Admin\MarkdownController::class, 'preview']);
});
