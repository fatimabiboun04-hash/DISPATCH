<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanningSandboxItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'user_id',
        'shift_id',
        'team_id',
        'date',
        'week_number',
        'year',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'week_number' => 'integer',
        'year' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
