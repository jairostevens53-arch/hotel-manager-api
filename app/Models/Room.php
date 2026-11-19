<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $fillable = ['number', 'floor', 'type', 'beds', 'status', 'active', 'notes'];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'beds' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function stays()
    {
        return $this->hasMany(Stay::class);
    }

    public function currentStay()
    {
        return $this->hasOne(Stay::class)->where('status', 'activa');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
