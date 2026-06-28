<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Planning extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'team_id',
        'shift_id',
        'date',
        'week_number',
        'year',
        'notes',
        'created_by',
        'is_locked',
    ];

    protected $casts = [
        'date' => 'date',
        'week_number' => 'integer',
        'year' => 'integer',
        'is_locked' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pointages()
    {
        return $this->hasMany(Pointage::class);
    }

    public function pauses()
    {
        return $this->hasMany(Pause::class);
    }
}
