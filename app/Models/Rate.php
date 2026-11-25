<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rate extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'hours', 'price', 'extra_hour_price', 'room_type', 'beds', 'active'];

    protected function casts(): array
    {
        return [
            'hours' => 'integer',
            'beds' => 'integer',
            'price' => 'decimal:2',
            'extra_hour_price' => 'decimal:2',
            'active' => 'boolean',
            'beds' => 'integer',
        ];
    }

    public function appliesToRoom(Room $room): bool
    {
        $expectedBeds = $room->type === 'estandar' ? 1 : $room->beds;

        return $this->room_type === $room->type
            && (int) $this->beds === (int) $expectedBeds;
    }

    public function stays()
    {
        return $this->hasMany(Stay::class);
    }
}
