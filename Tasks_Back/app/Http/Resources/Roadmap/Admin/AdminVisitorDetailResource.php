<?php

namespace App\Http\Resources\Roadmap\Admin;

use App\Models\Roadmap\Visitor;
use Illuminate\Http\Request;

/**
 * AdminVisitorDetail. Set on the model before resolving: `recent_posts`, `recent_comments`,
 * `recent_votes` (collections of plain rows) and `same_ip_visitors` (int).
 *
 * @mixin Visitor
 */
class AdminVisitorDetailResource extends AdminVisitorResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return parent::toArray($request) + [
            'recent_posts' => collect($r->getAttribute('recent_posts') ?? [])->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'board_slug' => $p->board_slug,
                'title' => $p->title,
                'moderation_state' => $p->moderation_state,
                'created_at' => AdminSupport::iso($p->created_at),
            ])->values()->all(),
            'recent_comments' => collect($r->getAttribute('recent_comments') ?? [])->map(fn ($c) => [
                'id' => $c->id,
                'post_id' => $c->post_id,
                'body' => mb_substr((string) $c->body, 0, 300),
                'moderation_state' => $c->moderation_state,
                'created_at' => AdminSupport::iso($c->created_at),
            ])->values()->all(),
            'recent_votes' => collect($r->getAttribute('recent_votes') ?? [])->map(fn ($v) => [
                'post_id' => $v->post_id,
                'post_title' => $v->post_title,
                'created_at' => AdminSupport::iso($v->created_at),
            ])->values()->all(),
            'same_ip_visitors' => (int) ($r->getAttribute('same_ip_visitors') ?? 0),
        ];
    }
}
