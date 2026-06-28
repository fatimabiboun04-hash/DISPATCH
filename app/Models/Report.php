<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'week_number',
        'year',
        'start_date',
        'end_date',
        'file_path',
        'file_type',
        'generated_by',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'week_number' => 'integer',
        'year' => 'integer',
    ];

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
