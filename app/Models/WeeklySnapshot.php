<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeeklySnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'week_number',
        'year',
        'total_employees',
        'total_planned',
        'total_checked_in',
        'total_absences',
        'avg_coverage',
        'total_overtime_hours',
        'overtime_employee_count',
        'under_hours_employee_count',
        'generated_at',
    ];

    protected $casts = [
        'week_number' => 'integer',
        'year' => 'integer',
        'generated_at' => 'datetime',
        'avg_coverage' => 'decimal:1',
        'total_overtime_hours' => 'decimal:1',
    ];
}
