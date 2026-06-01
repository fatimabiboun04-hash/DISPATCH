<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'pointage_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'distance_from_office',
        'is_valid',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'accuracy_meters' => 'decimal:2',
        'distance_from_office' => 'decimal:2',
        'is_valid' => 'boolean',
    ];

    public function pointage()
    {
        return $this->belongsTo(Pointage::class);
    }
}
