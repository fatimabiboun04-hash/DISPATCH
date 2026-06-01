<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'rated_by',
        'type',
        'reason',
        'week_number',
        'year',
    ];

    protected $casts = [
        'week_number' => 'integer',
        'year' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rater()
    {
        return $this->belongsTo(User::class, 'rated_by');
    }
}
