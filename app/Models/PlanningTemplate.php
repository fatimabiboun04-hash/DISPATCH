<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanningTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'week_number',
        'year',
        'created_by',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PlanningTemplateItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
