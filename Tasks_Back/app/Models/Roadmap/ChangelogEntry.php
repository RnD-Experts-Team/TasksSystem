<?php

namespace App\Models\Roadmap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Roadmap module model. Extends plain Model on purpose (NOT BaseModel: it drops
 * time of day) and carries NO global scopes: public code must never inherit the
 * fail-open scopes of Project/Task.
 */
class ChangelogEntry extends Model
{
    protected $table = 'roadmap_changelog_entries';

    protected $fillable = ['board_id', 'title', 'slug', 'summary', 'label', 'body_md', 'body_html', 'status', 'published_at', 'created_by', 'updated_by'];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'roadmap_changelog_post', 'changelog_entry_id', 'post_id');
    }

    /** Published and already due (scheduled entries stay hidden until their date). */
    public function scopeLive($query)
    {
        return $query->where('status', 'published')->where('published_at', '<=', now());
    }
}
