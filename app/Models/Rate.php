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
        ];
    }

    public function stays()
    {
        return $this->hasMany(Stay::class);
    }
}
