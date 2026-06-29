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
        'score',
        'reason',
        'comment',
        'week_number',
        'year',
    ];

    protected $casts = [
        'score' => 'integer',
        'week_number' => 'integer',
        'year' => 'integer',
    ];

    public static function typeFromScore(?int $score): ?string
    {
        return match (true) {
            $score >= 4 => 'excellent',
            $score >= 2 => 'average',
            $score === 1 => 'warning',
            default => null,
        };
    }

    public static function scoreLabel(?int $score): string
    {
        return match ($score) {
            5 => 'Excellent',
            4 => 'Very Good',
            3 => 'Good',
            2 => 'Average',
            1 => 'Needs Improvement',
            default => 'Not rated',
        };
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rater()
    {
        return $this->belongsTo(User::class, 'rated_by');
    }
}
