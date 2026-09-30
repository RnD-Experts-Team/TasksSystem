<?php

namespace App\Models\Roadmap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Roadmap module model. Extends plain Model on purpose (NOT BaseModel: it drops
 * time of day) and carries NO global scopes: public code must never inherit the
 * fail-open scopes of Project/Task.
 */
class Comment extends Model
{
    protected $table = 'roadmap_comments';

    protected $fillable = ['post_id', 'parent_id', 'original_post_id', 'visitor_id', 'user_id', 'is_admin', 'author_name', 'body', 'moderation_state', 'moderated_at', 'ip_hash', 'content_hash', 'flags'];

    protected $casts = [
        'is_admin' => 'boolean',
        'flags' => 'array',
        'moderated_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }
}
