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
class Status extends Model
{
    protected $table = 'roadmap_statuses';

    protected $fillable = ['board_id', 'name', 'slug', 'color', 'kind', 'sort_order', 'is_roadmap_column', 'is_default', 'locks_voting'];

    protected $casts = [
        'is_roadmap_column' => 'boolean',
        'is_default' => 'boolean',
        'locks_voting' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'status_id');
    }
}
