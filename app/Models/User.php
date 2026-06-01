<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'suspension_reason',
        'phone',
        'description',
        'avatar',
        'weekly_hours_limit',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'weekly_hours_limit' => 'decimal:2',
    ];
    protected $appends = ['avatar_url'];

    // ── Accessors ──

    /**
     * Generate initials from name for avatar fallback.
     * "Ahmed Benali" → "AB"
     */
    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->name);
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
        return $initials;
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }

    public function scopeEmployees($query)
    {
        return $query->where('role', 'employee');
    }

    // ── Relationships ──

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot('joined_at')
            ->withTimestamps();
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'skill_user')
            ->withPivot('level', 'certified_at')
            ->withTimestamps();
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function plannings()
    {
        return $this->hasMany(Planning::class);
    }

    public function pointages()
    {
        return $this->hasMany(Pointage::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function createdPlannings()
    {
        return $this->hasMany(Planning::class, 'created_by');
    }

    public function approvedLeaves()
    {
        return $this->hasMany(LeaveRequest::class, 'approved_by');
    }

    public function verifiedPointages()
    {
        return $this->hasMany(Pointage::class, 'verified_by');
    }

    // ── Helper Methods ──

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
    
    public function pauses()
    {
        return $this->hasMany(Pause::class);
    }
   // add this accessor:

public function getAvatarUrlAttribute(): ?string
{
    if (!$this->avatar) {
        return null; // Frontend generates initials avatar when null
    }
    return Storage::disk('public')->url($this->avatar);
}



}