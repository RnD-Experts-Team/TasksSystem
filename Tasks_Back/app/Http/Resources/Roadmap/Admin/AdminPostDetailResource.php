<?php

namespace App\Http\Resources\Roadmap\Admin;

use App\Models\Roadmap\Post;
use Illuminate\Http\Request;

/**
 * AdminPostDetail (list item + body, response, history, merges, votes by ip). Load it with
 * AdminPostService::findDetailed().
 *
 * @mixin Post
 */
class AdminPostDetailResource extends AdminPostResource
{
    public function toArray(Request $request): array
    {
        $names = $this->relationLoaded('changerNames') ? $this->getRelation('changerNames') : collect();

        return parent::toArray($request) + [
            'body' => $this->body,
            'response_md' => $this->response_md,
            'response_html' => $this->response_html,
            'responded_at' => AdminSupport::iso($this->responded_at),
            'moderation_reason' => $this->moderation_reason,
            'status_history' => $this->relationLoaded('statusChanges')
                ? $this->statusChanges->values()->map(fn ($c) => [
                    'id' => $c->id,
                    'from' => AdminSupport::status($c->fromStatus),
                    'to' => AdminSupport::status($c->toStatus) ?? ['id' => $c->to_status_id, 'slug' => 'deleted', 'name' => 'Deleted status', 'color' => '#64748b', 'kind' => 'open'],
                    'note' => $c->note,
                    'is_public' => (bool) $c->is_public,
                    'changed_by_name' => $c->changed_by ? ($names[$c->changed_by] ?? null) : null,
                    'at' => AdminSupport::iso($c->created_at),
                ])->all()
                : [],
            'merged_from' => $this->relationLoaded('mergedFrom')
                ? $this->getRelation('mergedFrom')->map(fn ($p) => ['id' => $p->id, 'number' => $p->number, 'title' => $p->title])->values()->all()
                : [],
            'public_url_path' => '/roadmap/'.$this->board?->slug.'/p/'.$this->number.'-'.$this->slug,
            'votes_by_ip' => $this->relationLoaded('votesByIp')
                ? $this->getRelation('votesByIp')->map(fn ($r) => ['ip_hash_short' => (string) $r->ip_short, 'count' => (int) $r->votes])->values()->all()
                : [],
        ];
    }
}
