<?php

// app/Models/Pause.php

namespace App\Models;

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
        'pause_start',
        'pause_end',
    ];

   protected $casts = [
    'pause_start' => 'datetime',
    'pause_end'   => 'datetime',
];

    // Relationships
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

    // Calculate pause duration in minutes
    public function getDurationMinutesAttribute(): int
    {
        $start = \Carbon\Carbon::parse($this->pause_start);
        $end = \Carbon\Carbon::parse($this->pause_end);
        return $start->diffInMinutes($end);
    }

    // Check if pause is currently active
       // Check if pause is currently active
    public function getIsActiveAttribute(): bool
    {
        $now = now();
        $today = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        // Only active if on the same date as the linked planning
        $planningDate = $this->planning?->date?->toDateString();

        if ($planningDate !== $today) {
            return false;
        }

        return $currentTime >= $this->pause_start && $currentTime <= $this->pause_end;
    }
}
