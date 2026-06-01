<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fingerprint',
        'name',
        'is_trusted',
        'trusted_at',
        'last_used_at',
    ];

    protected $casts = [
        'is_trusted' => 'boolean',
        'trusted_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
