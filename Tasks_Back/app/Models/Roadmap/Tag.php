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
class Tag extends Model
{
    protected $table = 'roadmap_tags';

    protected $fillable = ['board_id', 'name', 'slug', 'color', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'roadmap_post_tag', 'tag_id', 'post_id');
    }
}
