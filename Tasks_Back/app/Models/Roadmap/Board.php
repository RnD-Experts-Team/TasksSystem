<?php

namespace App\Models\Roadmap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Roadmap module model. Extends plain Model on purpose (NOT BaseModel: it drops
 * time of day) and carries NO global scopes: public code must never inherit the
 * fail-open scopes of Project/Task.
 */
class Board extends Model
{
    protected $table = 'roadmap_boards';

    protected $fillable = ['slug', 'name', 'description', 'icon', 'sort_order', 'is_archived', 'voting_mode', 'allow_submissions', 'allow_comments', 'allow_votes', 'require_post_approval', 'require_comment_approval', 'trust_after_approved', 'next_post_number'];

    protected $casts = [
        'is_archived' => 'boolean',
        'allow_submissions' => 'boolean',
        'allow_comments' => 'boolean',
        'allow_votes' => 'boolean',
        'require_post_approval' => 'boolean',
        'require_comment_approval' => 'boolean',
        'sort_order' => 'integer',
        'trust_after_approved' => 'integer',
        'next_post_number' => 'integer',
    ];

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class, 'board_id')->orderBy('sort_order');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'board_id')->orderBy('sort_order');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'board_id');
    }
}
