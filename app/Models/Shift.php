<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'start_time',
        'end_time',
        'break_minutes',
        'color',
        'is_active',
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'break_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function plannings()
    {
        return $this->hasMany(Planning::class);
    }

    /**
     * Calculate shift duration in minutes (excluding break).
     */
    public function getDurationMinutesAttribute(): int
    {
        $start = \Carbon\Carbon::parse($this->start_time);
        $end = \Carbon\Carbon::parse($this->end_time);
        
        // Handle night shifts crossing midnight
        if ($end->lessThan($start)) {
            $end->addDay();
        }
        
        return $start->diffInMinutes($end) - $this->break_minutes;
    }

    /**
     * Calculate shift duration in hours.
     */
    public function getDurationHoursAttribute(): float
    {
        return round($this->duration_minutes / 60, 2);
    }
}