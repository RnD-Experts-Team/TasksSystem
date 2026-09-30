<?php

namespace App\Models\Roadmap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Roadmap module model. Extends plain Model on purpose (NOT BaseModel: it drops
 * time of day) and carries NO global scopes: public code must never inherit the
 * fail-open scopes of Project/Task.
 */
class Visitor extends Model
{
    protected $table = 'roadmap_visitors';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'token_hash', 'first_ip_hash', 'last_ip_hash', 'ua_hash', 'first_seen_at', 'last_seen_at', 'is_banned', 'banned_reason', 'banned_at', 'banned_by', 'is_trusted', 'posts_count', 'approved_posts_count', 'comments_count', 'approved_comments_count', 'votes_count'];

    protected $casts = [
        'is_banned' => 'boolean',
        'is_trusted' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'banned_at' => 'datetime',
        'posts_count' => 'integer',
        'approved_posts_count' => 'integer',
        'comments_count' => 'integer',
        'approved_comments_count' => 'integer',
        'votes_count' => 'integer',
    ];

    protected $hidden = ['token_hash'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'visitor_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class, 'visitor_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'visitor_id');
    }
}
