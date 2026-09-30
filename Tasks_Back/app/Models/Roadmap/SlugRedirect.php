<?php

namespace App\Models\Roadmap;

use Illuminate\Database\Eloquent\Model;

/**
 * Roadmap module model. Extends plain Model on purpose (NOT BaseModel: it drops
 * time of day) and carries NO global scopes: public code must never inherit the
 * fail-open scopes of Project/Task.
 */
class SlugRedirect extends Model
{
    protected $table = 'roadmap_slug_redirects';

    public $timestamps = false;

    protected $fillable = ['kind', 'old_key', 'target_id', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
