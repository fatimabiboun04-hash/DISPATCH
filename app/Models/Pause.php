<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pause extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'team_id',
        'planning_id',
        'type',
        'reason',
        'status',
        'pause_start',
        'pause_end',
        'duration_minutes',
        'cancelled_at',
        'cancelled_by',
        'created_by',
    ];

    protected $casts = [
        'pause_start' => 'datetime',
        'pause_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    protected $appends = [
        'duration_minutes',
        'is_active',
        'is_editable',
        'is_cancellable',
        'status_label',
        'type_label',
    ];

    public const TYPES = [
        'break' => 'Pause',
        'lunch' => 'Déjeuner',
        'medical' => 'Médicale',
        'technical' => 'Technique',
        'training' => 'Formation',
        'other' => 'Autre',
    ];

    public const STATUSES = [
        'scheduled' => 'Planifiée',
        'active' => 'En cours',
        'completed' => 'Terminée',
        'cancelled' => 'Annulée',
        'expired' => 'Expirée',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function planning(): BelongsTo
    {
        return $this->belongsTo(Planning::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Accessors ──

    public function getDurationMinutesAttribute(): int
    {
        if ($this->pause_start && $this->pause_end) {
            return $this->pause_start->diffInMinutes($this->pause_end);
        }
        return $this->attributes['duration_minutes'] ?? 0;
    }

    public function getIsActiveAttribute(): bool
    {
        $now = now();
        return $this->status === 'active'
            || ($this->status === 'scheduled'
                && $this->pause_start <= $now
                && $this->pause_end > $now);
    }

    public function getIsEditableAttribute(): bool
    {
        return $this->status === 'scheduled';
    }

    public function getIsCancellableAttribute(): bool
    {
        return in_array($this->status, ['scheduled', 'active']);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    // ── Helpers ──

    public function isCancellable(): bool
    {
        return in_array($this->status, ['scheduled', 'active']);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['scheduled']);
    }
}
