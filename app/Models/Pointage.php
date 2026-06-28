<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pointage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'planning_id',
        'check_in_at',
        'check_out_at',
        'scheduled_start',
        'scheduled_end',
        'status',
        'worked_minutes',
        'delay_minutes',
        'early_leave_minutes',
        'overtime_minutes',
        'verification_data',
        'is_flagged',
        'flag_reason',
        'verified_by',
    ];

    protected $casts = [
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'verification_data' => 'array',
        'is_flagged' => 'boolean',
        'worked_minutes' => 'integer',
        'delay_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'overtime_minutes' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function planning()
    {
        return $this->belongsTo(Planning::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function gpsLog()
    {
        return $this->hasOne(GpsLog::class);
    }
}
