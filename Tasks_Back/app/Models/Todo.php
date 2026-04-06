<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Todo extends Model
{
    protected $fillable = [
        'workspace_id',
        'parent_id',
        'title',
        'status',
        'due_date',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function parent()
    {
        return $this->belongsTo(Todo::class, 'parent_id');
    }

    public function subtodos()
    {
        return $this->hasMany(Todo::class, 'parent_id');
    }
}
