<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'leader_id', 'color'];

    protected $casts = [
        'leader_id' => 'integer',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('joined_at')
            ->withTimestamps();
    }

    public function leader()
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function plannings()
    {
        return $this->hasMany(Planning::class);
    }

    public function pauses()
    {
        return $this->hasMany(Pause::class);
    }
}
