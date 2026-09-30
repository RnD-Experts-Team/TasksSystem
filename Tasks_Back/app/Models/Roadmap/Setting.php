<?php

namespace App\Models\Roadmap;

use Illuminate\Database\Eloquent\Model;

/**
 * Roadmap module model. Extends plain Model on purpose (NOT BaseModel: it drops
 * time of day) and carries NO global scopes: public code must never inherit the
 * fail-open scopes of Project/Task.
 */
class Setting extends Model
{
    protected $table = 'roadmap_settings';

    protected $fillable = ['scope', 'data'];

    protected $casts = [
        'data' => 'array',
    ];
}
