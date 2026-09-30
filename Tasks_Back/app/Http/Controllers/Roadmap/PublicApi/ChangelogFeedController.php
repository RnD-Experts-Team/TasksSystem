<?php

namespace App\Http\Controllers\Roadmap\PublicApi;

use App\Services\Roadmap\RssService;
use Illuminate\Http\Response;

class ChangelogFeedController extends PublicApiController
{
    public function __construct(private RssService $rss) {}

    /** GET /changelog/feed.xml: RSS 2.0 built by RssService::changelogFeed(). */
    public function rss(): Response
    {
        return response($this->rss->changelogFeed(), 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
