<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'skill_user')
            ->withPivot('level', 'certified_at')
            ->withTimestamps();
    }

    public function shifts()
    {
        return $this->belongsToMany(Shift::class, 'shift_skill');
    }
}
