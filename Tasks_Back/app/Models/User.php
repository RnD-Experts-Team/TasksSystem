<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use Carbon\Carbon;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_path'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected $appends = ['avatar_url'];


    protected $guard_name = 'sanctum';
    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class)
            ->withPivot('role')
            ->withTimestamps();
    }


    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'stakeholder_id');
    }

    public function assignedTasks(): BelongsToMany
        {
            return $this->belongsToMany(Task::class)
                        ->withPivot('percentage')
                        ->withTimestamps();
        }
    public function getAvatarLocalPathAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        // Assuming you store in public disk; adjust if different
        $fullPath = Storage::disk('public')->path($this->avatar_path);

        if (file_exists($fullPath)) {
            return $fullPath;
        }

        return null;
    }
// Get tasks assigned to user for a specific project
public function getTasksForProject(int $projectId): BelongsToMany
{
    return $this->assignedTasks()
                ->whereHas('section', function ($query) use ($projectId) {
                    $query->where('project_id', $projectId);
                });
}
public function requestedHelp(): HasMany
{
    return $this->hasMany(HelpRequest::class, 'requester_id');
}

// Help requests this user is helping with
public function helpingWith(): HasMany
{
    return $this->hasMany(HelpRequest::class, 'helper_id');
}

// Get available help requests (not claimed and not completed)
public function getAvailableHelpRequests()
{
    return HelpRequest::where('helper_id', null)
                     ->where('is_completed', false)
                     ->with(['task.section.project', 'requester'])
                     ->latest();
}

public function requestedTickets(): HasMany
{
    return $this->hasMany(Ticket::class, 'requester_id');
}

// Tickets assigned to this user
public function assignedTickets(): HasMany
{
    return $this->hasMany(Ticket::class, 'assigned_to');
}

// Get available tickets (not assigned and open)
public function getAvailableTickets()
{
    return Ticket::where('assigned_to', null)
                 ->where('status', 'open')
                 ->with(['requester'])
                 ->latest();
}

public function getAvatarUrlAttribute(): ?string
{
    if (!$this->avatar_path) {
        return null;
    }

    /** @var FilesystemAdapter $disk */
    $disk = Storage::disk('public');

    return $disk->url($this->avatar_path);
}

public function clockSessions(): HasMany
{
    return $this->hasMany(ClockSession::class);
}

public function activeClockSession()
{
    return $this->hasOne(ClockSession::class)
                ->whereIn('status', ['active', 'on_break'])
                ->latest('clock_in_utc');
}


}
