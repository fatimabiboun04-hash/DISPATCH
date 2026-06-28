<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanningAudit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'planning_id',
        'user_id',
        'action',
        'old_values',
        'new_values',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function planning(): BelongsTo
    {
        return $this->belongsTo(Planning::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            throw new \RuntimeException('Planning audit records are immutable and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('Planning audit records are immutable and cannot be deleted.');
        });
    }
}
