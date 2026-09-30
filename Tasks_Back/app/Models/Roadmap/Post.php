<?php

namespace App\Models\Roadmap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Roadmap module model. Extends plain Model on purpose (NOT BaseModel: it drops
 * time of day) and carries NO global scopes: public code must never inherit the
 * fail-open scopes of Project/Task.
 */
class Post extends Model
{
    protected $table = 'roadmap_posts';

    protected $fillable = ['board_id', 'number', 'slug', 'title', 'body', 'author_name', 'visitor_id', 'created_by_user_id', 'ip_hash', 'content_hash', 'moderation_state', 'moderated_at', 'moderated_by', 'moderation_reason', 'published_at', 'status_id', 'is_pinned', 'roadmap_order', 'votes_count', 'comments_count', 'response_md', 'response_html', 'responded_at', 'responded_by', 'merged_into_post_id', 'merged_at', 'flags', 'last_activity_at'];

    protected $casts = [
        'is_pinned' => 'boolean',
        'number' => 'integer',
        'votes_count' => 'integer',
        'comments_count' => 'integer',
        'roadmap_order' => 'integer',
        'flags' => 'array',
        'published_at' => 'datetime',
        'moderated_at' => 'datetime',
        'responded_at' => 'datetime',
        'merged_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'roadmap_post_tag', 'post_id', 'tag_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class, 'post_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(StatusChange::class, 'post_id')->orderBy('created_at');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_post_id');
    }

    public function changelogEntries(): BelongsToMany
    {
        return $this->belongsToMany(ChangelogEntry::class, 'roadmap_changelog_post', 'post_id', 'changelog_entry_id');
    }

    /** Publicly visible: approved and not merged away. */
    public function scopePubliclyVisible($query)
    {
        return $query->where('moderation_state', 'approved')->whereNull('merged_into_post_id');
    }
}
