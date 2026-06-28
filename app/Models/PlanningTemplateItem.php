<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanningTemplateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'planning_template_id',
        'user_id',
        'shift_id',
        'team_id',
        'day_of_week',
        'notes',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PlanningTemplate::class, 'planning_template_id');
    }

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
}
