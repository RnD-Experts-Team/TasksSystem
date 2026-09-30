<?php

namespace App\Console\Commands\Roadmap;

use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Post;
use App\Services\Roadmap\MarkdownRenderer;
use Illuminate\Console\Command;

class RerenderRoadmapMarkdown extends Command
{
    protected $signature = 'roadmap:rerender';

    protected $description = 'Re-render stored markdown (official responses, changelog bodies) with the current renderer rules';

    public function handle(): int
    {
        $renderer = app(MarkdownRenderer::class);
        $posts = 0;
        $entries = 0;

        Post::query()->whereNotNull('response_md')->chunkById(200, function ($chunk) use ($renderer, &$posts) {
            foreach ($chunk as $post) {
                $post->update(['response_html' => $renderer->render($post->response_md)]);
                $posts++;
            }
        });

        ChangelogEntry::query()->whereNotNull('body_md')->chunkById(200, function ($chunk) use ($renderer, &$entries) {
            foreach ($chunk as $entry) {
                $entry->update(['body_html' => $renderer->render($entry->body_md)]);
                $entries++;
            }
        });

        $this->info("Re-rendered {$posts} responses and {$entries} changelog entries.");

        return self::SUCCESS;
    }
}
