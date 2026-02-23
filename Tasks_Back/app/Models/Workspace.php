<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    protected $fillable = ['name', 'description'];

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function todos()
    {
        return $this->hasMany(Todo::class);
    }
}
