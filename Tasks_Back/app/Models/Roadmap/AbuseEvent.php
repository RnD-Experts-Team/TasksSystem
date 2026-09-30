<?php

namespace App\Models\Roadmap;

use Illuminate\Database\Eloquent\Model;

/**
 * Roadmap module model. Extends plain Model on purpose (NOT BaseModel: it drops
 * time of day) and carries NO global scopes: public code must never inherit the
 * fail-open scopes of Project/Task.
 */
class AbuseEvent extends Model
{
    protected $table = 'roadmap_abuse_events';

    public $timestamps = false;

    protected $fillable = ['type', 'visitor_id', 'ip_hash', 'board_id', 'meta', 'created_at'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}
