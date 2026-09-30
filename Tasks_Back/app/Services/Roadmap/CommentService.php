<?php

namespace App\Services\Roadmap;

use App\Events\Roadmap\CommentApproved;
use App\Models\Roadmap\Comment;
use App\Models\Roadmap\Post;
use App\Models\Roadmap\Visitor;
use App\Support\Roadmap\RoadmapBusinessException;
use App\Support\Roadmap\RoadmapLimitException;
use App\Support\Roadmap\RoadmapNotFound;
use App\Support\Roadmap\RuntimeSettings;
use App\Support\Roadmap\TextSanitizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Public comment reads (approved only) and anonymous submissions. */
class CommentService
{
    public const PER_PAGE = 30;

    public function __construct(
        private BoardService $boards,
        private PostService $posts,
        private FormTokenService $formTokens,
        private AbuseGuardService $abuse,
        private PostCounters $counters,
    ) {}

    /**
     * Top-level approved comments, oldest first, paginated; each with its approved replies.
     *
     * @return array{paginator: LengthAwarePaginator, replies: Collection<int,Collection<int,Comment>>}
     *
     * @throws RoadmapNotFound
     */
    public function list(string $boardSlug, int $number, int $page): array
    {
        $board = $this->boards->findBySlug($boardSlug);
        $post = $this->posts->findVisible($board, $number);

        $paginator = Comment::query()
            ->where('post_id', $post->id)->where('moderation_state', 'approved')->whereNull('parent_id')
            ->orderBy('created_at')->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        $replies = Comment::query()
            ->where('post_id', $post->id)->where('moderation_state', 'approved')
            ->whereIn('parent_id', $paginator->getCollection()->pluck('id'))
            ->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('parent_id');

        return ['paginator' => $paginator, 'replies' => $replies];
    }

    /**
     * @param  array{body: string, author_name?: ?string, parent_id?: ?int, form_token: string}  $data  already sanitised by the FormRequest
     * @return array{id: ?int, moderation_state: string}
     *
     * @throws RoadmapBusinessException
     * @throws RoadmapLimitException
     * @throws ValidationException
     */
    public function submit(string $boardSlug, int $number, Visitor $visitor, array $data, string $honeypot, string $ipHash): array
    {
        $board = $this->boards->findBySlug($boardSlug);
        $post = $this->posts->findVisible($board, $number);

        $claims = $this->formTokens->verify($data['form_token'] ?? null, 'comment', $board, $visitor);
        $this->formTokens->consume($claims);

        if (trim($honeypot) !== '') {
            $this->abuse->log('honeypot', $visitor->id, $ipHash, $board->id, ['kind' => 'comment']);

            return ['id' => null, 'moderation_state' => 'pending'];
        }

        if ($visitor->is_banned) {
            $this->abuse->log('banned_write', $visitor->id, $ipHash, $board->id, ['kind' => 'comment']);

            return ['id' => null, 'moderation_state' => 'pending'];
        }

        if (! $board->allow_comments || ! $this->commentsEnabled()) {
            throw new RoadmapBusinessException('comments_closed', 'Comments are closed for this idea.');
        }

        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            $parentOk = Comment::query()->whereKey($parentId)->where('post_id', $post->id)
                ->whereNull('parent_id')->where('moderation_state', 'approved')->exists();
            if (! $parentOk) {
                throw ValidationException::withMessages(['parent_id' => ['The comment you are replying to is not available.']]);
            }
        }

        $body = $data['body'];
        $hash = TextSanitizer::contentHash($body, (string) $post->id);
        $duplicate = Comment::query()->where('visitor_id', $visitor->id)->where('content_hash', $hash)
            ->where('created_at', '>=', now()->subDay())->exists();
        if ($duplicate) {
            $this->abuse->log('duplicate', $visitor->id, $ipHash, $board->id, ['kind' => 'comment']);
            throw new RoadmapBusinessException('duplicate_content', 'You have already posted this comment.');
        }

        $inspection = $this->abuse->inspectText($body, 'comment');
        $flags = [];
        $state = null;
        if ($inspection['spam_term'] !== null) {
            $state = 'spam';
            $flags[] = 'blocklist';
            $this->abuse->log('spam_keyword', $visitor->id, $ipHash, $board->id, ['kind' => 'comment']);
        } elseif ($inspection['too_many_links']) {
            $state = 'pending';
            $flags[] = 'links:'.$inspection['links'];
            $this->abuse->log('links', $visitor->id, $ipHash, $board->id, ['kind' => 'comment', 'links' => $inspection['links']]);
        }

        $this->abuse->assertCommentCaps($visitor, $ipHash);

        if ($state === null) {
            $auto = ! $board->require_comment_approval || $this->abuse->isTrusted($visitor, $board, 'comment');
            $state = $auto ? 'approved' : 'pending';
        }

        $comment = DB::transaction(function () use ($post, $visitor, $data, $parentId, $body, $hash, $ipHash, $state, $flags) {
            $comment = Comment::create([
                'post_id' => $post->id,
                'parent_id' => $parentId,
                'visitor_id' => $visitor->id,
                'is_admin' => false,
                'author_name' => $data['author_name'] ?? null,
                'body' => $body,
                'moderation_state' => $state,
                'moderated_at' => $state === 'approved' ? now() : null,
                'ip_hash' => $ipHash,
                'content_hash' => $hash,
                'flags' => $flags === [] ? null : $flags,
            ]);

            Visitor::query()->whereKey($visitor->id)->increment('comments_count');
            if ($state === 'approved') {
                Visitor::query()->whereKey($visitor->id)->increment('approved_comments_count');
                $this->counters->recountComments($post->id);
                Post::query()->whereKey($post->id)->update(['last_activity_at' => now()]);
            }

            return $comment;
        });

        if ($state === 'approved') {
            CommentApproved::dispatch($comment);
        }

        return [
            'id' => $state === 'approved' ? (int) $comment->id : null,
            'moderation_state' => $state === 'approved' ? 'approved' : 'pending',
        ];
    }

    private function commentsEnabled(): bool
    {
        return (bool) RuntimeSettings::get('features.comments', true);
    }
}
