<?php

namespace Database\Seeders;

use App\Models\Roadmap\Board;
use App\Models\Roadmap\ChangelogEntry;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Status;
use App\Models\Roadmap\StatusChange;
use App\Models\Roadmap\Tag;
use App\Models\Roadmap\Visitor;
use App\Services\Roadmap\MarkdownRenderer;
use App\Services\Roadmap\PostCounters;
use App\Support\Roadmap\IpHasher;
use App\Support\Roadmap\TextSanitizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Optional demo content for local / staging: one board "Tasks System" with the default statuses,
 * three tags, a dozen approved ideas (varied votes and statuses, three roadmap columns),
 * two pending ideas, an official response and two changelog entries.
 *
 * Idempotent: does nothing when the demo board already exists.
 */
class RoadmapDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Board::query()->where('slug', 'tasks-system')->exists()) {
            $this->say('Demo board already exists; nothing to do.');

            return;
        }

        DB::transaction(function () {
            $board = Board::create([
                'slug' => 'tasks-system',
                'name' => 'Tasks System',
                'description' => 'Ideas and feedback for the PNE Tasks System.',
                'icon' => 'list-checks',
                'sort_order' => 1,
                'require_post_approval' => true,
                'require_comment_approval' => true,
            ]);

            $statuses = $this->statuses($board);
            $tags = $this->tags($board);
            $voters = $this->voters(40);

            $posts = [];
            foreach ($this->postDefinitions() as $def) {
                $posts[$def['key']] = $this->createPost($board, $statuses, $tags, $voters, $def);
            }

            $this->pendingPosts($board, $statuses, $voters);
            $this->response($posts['dark-mode']);
            $this->comments($posts['dark-mode'], $voters);
            $this->changelog($board, $posts);

            $board->update(['next_post_number' => Post::query()->where('board_id', $board->id)->max('number') + 1]);

            $counters = app(PostCounters::class);
            $counters->recountPosts(Post::query()->where('board_id', $board->id)->pluck('id')->all());
            $counters->recountVisitors();
        });

        $this->say('Roadmap demo data created (board "tasks-system").');
    }

    /** @return array<string,Status> keyed by slug */
    private function statuses(Board $board): array
    {
        $rows = [
            ['Under review', 'under-review', '#64748b', 'open', false, true, false],
            ['Planned', 'planned', '#6366f1', 'planned', true, false, false],
            ['In progress', 'in-progress', '#f59e0b', 'in_progress', true, false, false],
            ['Live', 'live', '#10b981', 'done', true, false, false],
            ['Not planned', 'not-planned', '#ef4444', 'closed', false, false, true],
        ];

        $out = [];
        foreach ($rows as $i => [$name, $slug, $color, $kind, $column, $default, $locks]) {
            $out[$slug] = Status::create([
                'board_id' => $board->id, 'name' => $name, 'slug' => $slug, 'color' => $color, 'kind' => $kind,
                'sort_order' => $i, 'is_roadmap_column' => $column, 'is_default' => $default, 'locks_voting' => $locks,
            ]);
        }

        return $out;
    }

    /** @return array<string,Tag> */
    private function tags(Board $board): array
    {
        $out = [];
        foreach ([['Feature', 'feature', '#6366f1'], ['Improvement', 'improvement', '#0ea5e9'], ['Bug', 'bug', '#ef4444']] as $i => [$name, $slug, $color]) {
            $out[$slug] = Tag::create(['board_id' => $board->id, 'name' => $name, 'slug' => $slug, 'color' => $color, 'sort_order' => $i]);
        }

        return $out;
    }

    /** @return array<int,Visitor> */
    private function voters(int $count): array
    {
        $ip = IpHasher::hash('192.0.2.1');
        $voters = [];
        for ($i = 0; $i < $count; $i++) {
            $voters[] = Visitor::create([
                'id' => (string) Str::ulid(),
                'token_hash' => hash('sha256', 'demo-visitor-'.Str::random(24)),
                'first_ip_hash' => $ip,
                'last_ip_hash' => $ip,
                'first_seen_at' => now()->subDays(20),
                'last_seen_at' => now()->subDays(rand(0, 10)),
            ]);
        }

        return $voters;
    }

    /** @return array<int,array<string,mixed>> */
    private function postDefinitions(): array
    {
        return [
            ['key' => 'dark-mode', 'title' => 'Dark mode for the whole app', 'body' => 'Long sessions at night are hard on the eyes. A proper dark theme would help a lot.', 'status' => 'in-progress', 'votes' => 34, 'tags' => ['feature'], 'age' => 25, 'pinned' => true],
            ['key' => 'csv', 'title' => 'Export tasks and reports to CSV', 'body' => 'Managers need to share progress in spreadsheets. CSV export on every list would cover it.', 'status' => 'planned', 'votes' => 27, 'tags' => ['feature'], 'age' => 20],
            ['key' => 'calendar', 'title' => 'Calendar view for due dates', 'body' => 'See every deadline of a project on a month calendar and drag to reschedule.', 'status' => 'planned', 'votes' => 22, 'tags' => ['feature'], 'age' => 18],
            ['key' => 'notifications', 'title' => 'Slack notifications for assigned tasks', 'body' => 'Send a message to a Slack channel when a task is assigned or its status changes.', 'status' => 'under-review', 'votes' => 19, 'tags' => ['feature', 'improvement'], 'age' => 14],
            ['key' => 'mobile', 'title' => 'Faster kanban board on mobile', 'body' => 'Dragging cards on a phone is laggy with more than 50 tasks in a column.', 'status' => 'in-progress', 'votes' => 15, 'tags' => ['improvement'], 'age' => 12],
            ['key' => 'keyboard', 'title' => 'Keyboard shortcuts for common actions', 'body' => 'Create a task with C, search with slash, and move between columns with the arrow keys.', 'status' => 'under-review', 'votes' => 11, 'tags' => ['improvement'], 'age' => 11],
            ['key' => 'timer-bug', 'title' => 'Work session timer resets after refresh', 'body' => 'If I refresh the page during a running session the timer starts from zero again.', 'status' => 'live', 'votes' => 9, 'tags' => ['bug'], 'age' => 30, 'shipped' => true],
            ['key' => 'templates', 'title' => 'Project templates', 'body' => 'Start a new project from a saved structure of sections and tasks.', 'status' => 'live', 'votes' => 8, 'tags' => ['feature'], 'age' => 28, 'shipped' => true],
            ['key' => 'attachments', 'title' => 'Bigger attachments on comments', 'body' => 'The upload limit is too small for design files.', 'status' => 'under-review', 'votes' => 6, 'tags' => ['improvement'], 'age' => 6],
            ['key' => 'gantt', 'title' => 'Gantt chart of project timelines', 'body' => 'A timeline view with dependencies between tasks.', 'status' => 'under-review', 'votes' => 4, 'tags' => ['feature'], 'age' => 4],
            ['key' => 'widgets', 'title' => 'Customisable dashboard widgets', 'body' => 'Let every user choose which widgets to show on the dashboard.', 'status' => 'under-review', 'votes' => 2, 'tags' => ['feature'], 'age' => 2],
            ['key' => 'offline', 'title' => 'Offline mode', 'body' => 'Keep working without a connection and sync later.', 'status' => 'not-planned', 'votes' => 1, 'tags' => ['feature'], 'age' => 22],
        ];
    }

    /**
     * @param  array<string,Status>  $statuses
     * @param  array<string,Tag>  $tags
     * @param  array<int,Visitor>  $voters
     * @param  array<string,mixed>  $def
     */
    private function createPost(Board $board, array $statuses, array $tags, array $voters, array $def): Post
    {
        $number = (int) Post::query()->where('board_id', $board->id)->max('number') + 1;
        $published = now()->subDays($def['age']);
        $status = $statuses[$def['status']];

        $post = Post::create([
            'board_id' => $board->id,
            'number' => $number,
            'slug' => TextSanitizer::slug($def['title']),
            'title' => $def['title'],
            'body' => $def['body'],
            'author_name' => ($number % 3 === 0) ? 'Sam' : null,
            'visitor_id' => $voters[$number % count($voters)]->id,
            'content_hash' => TextSanitizer::contentHash($def['title'], $def['body']),
            'moderation_state' => 'approved',
            'moderated_at' => $published,
            'published_at' => $published,
            'status_id' => $status->id,
            'is_pinned' => $def['pinned'] ?? false,
            'roadmap_order' => $number,
            'last_activity_at' => $published,
        ]);
        DB::table('roadmap_posts')->where('id', $post->id)->update(['created_at' => $published, 'updated_at' => $published]);

        $post->tags()->sync(array_map(fn ($slug) => $tags[$slug]->id, $def['tags']));

        // votes: distinct visitors, most of them recent so "trending" has something to rank
        $votes = min((int) $def['votes'], count($voters));
        $rows = [];
        foreach (array_slice(array_keys($voters), 0, $votes) as $i) {
            $rows[] = [
                'post_id' => $post->id,
                'visitor_id' => $voters[$i]->id,
                'ip_hash' => null,
                'created_at' => now()->subDays(rand(0, min(20, (int) $def['age'])))->subMinutes(rand(0, 1000)),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('roadmap_votes')->insert($chunk);
        }

        // status history for anything that moved out of the default status
        if ($status->slug !== 'under-review') {
            StatusChange::create([
                'post_id' => $post->id,
                'from_status_id' => $statuses['under-review']->id,
                'to_status_id' => $status->id,
                'note' => match ($status->slug) {
                    'planned' => 'We like this and have scheduled it.',
                    'in-progress' => 'Work has started.',
                    'live' => 'Shipped. Thanks for the idea!',
                    default => null,
                },
                'is_public' => true,
                'created_at' => $published->copy()->addDays(2),
            ]);
        }

        return $post->fresh();
    }

    /**
     * @param  array<string,Status>  $statuses
     * @param  array<int,Visitor>  $voters
     */
    private function pendingPosts(Board $board, array $statuses, array $voters): void
    {
        foreach ([
            ['Add a public API for tasks', 'We would like to integrate the tasks with our own tools.'],
            ['Show time spent per project', 'A simple total of hours per project on the project page.'],
        ] as $i => [$title, $body]) {
            $number = (int) Post::query()->where('board_id', $board->id)->max('number') + 1;
            Post::create([
                'board_id' => $board->id, 'number' => $number, 'slug' => TextSanitizer::slug($title), 'title' => $title, 'body' => $body,
                'visitor_id' => $voters[$i]->id, 'content_hash' => TextSanitizer::contentHash($title, $body),
                'moderation_state' => 'pending', 'status_id' => $statuses['under-review']->id, 'last_activity_at' => now()->subHours(3 + $i),
            ]);
        }
    }

    private function response(Post $post): void
    {
        $md = "Thanks everyone for the votes.\n\nDark mode is **in progress** and lands in the next release.";
        $html = class_exists(MarkdownRenderer::class)
            ? app(MarkdownRenderer::class)->render($md)
            : '<p>Thanks everyone for the votes.</p><p>Dark mode is <strong>in progress</strong> and lands in the next release.</p>';

        $post->update(['response_md' => $md, 'response_html' => $html, 'responded_at' => now()->subDays(3)]);
    }

    /** @param  array<int,Visitor>  $voters */
    private function comments(Post $post, array $voters): void
    {
        $top = Comment::create([
            'post_id' => $post->id, 'visitor_id' => $voters[1]->id, 'author_name' => 'Alex',
            'body' => 'Yes please! Especially for the kanban board.', 'moderation_state' => 'approved',
        ]);
        Comment::create([
            'post_id' => $post->id, 'parent_id' => $top->id, 'is_admin' => true,
            'body' => 'Kanban is included in the first release.', 'moderation_state' => 'approved',
        ]);
    }

    /** @param  array<string,Post>  $posts */
    private function changelog(Board $board, array $posts): void
    {
        $entries = [
            [
                'title' => 'Project templates are here', 'slug' => 'project-templates', 'label' => 'new', 'days' => 27,
                'summary' => 'Start new projects from a saved structure.',
                'md' => "You can now save any project as a **template** and start new ones from it.\n\n- Sections and tasks are copied\n- Dates are shifted automatically",
                'html' => '<p>You can now save any project as a <strong>template</strong> and start new ones from it.</p><ul><li>Sections and tasks are copied</li><li>Dates are shifted automatically</li></ul>',
                'posts' => ['templates'],
            ],
            [
                'title' => 'Work session timer fix', 'slug' => 'work-session-timer-fix', 'label' => 'fixed', 'days' => 29,
                'summary' => 'The timer no longer resets after a refresh.',
                'md' => 'A running work session now survives a page refresh.',
                'html' => '<p>A running work session now survives a page refresh.</p>',
                'posts' => ['timer-bug'],
            ],
        ];

        foreach ($entries as $e) {
            $html = class_exists(MarkdownRenderer::class) ? app(MarkdownRenderer::class)->render($e['md']) : $e['html'];
            $entry = ChangelogEntry::create([
                'board_id' => $board->id, 'title' => $e['title'], 'slug' => $e['slug'], 'summary' => $e['summary'], 'label' => $e['label'],
                'body_md' => $e['md'], 'body_html' => $html, 'status' => 'published', 'published_at' => now()->subDays($e['days']),
            ]);
            $entry->posts()->sync(array_map(fn ($k) => $posts[$k]->id, $e['posts']));
        }
    }

    private function say(string $message): void
    {
        if ($this->command) {
            $this->command->info($message);
        }
    }
}
