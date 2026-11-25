<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    protected $fillable = [
        'guest_id', 'room_id', 'rate_id', 'created_by', 'stay_id',
        'room_type', 'beds', 'rate_units', 'check_in_at', 'expected_checkout_at',
        'guests', 'agreed_price', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'expected_checkout_at' => 'datetime',
            'guests' => 'integer',
            'beds' => 'integer',
            'rate_units' => 'integer',
            'agreed_price' => 'decimal:2',
        ];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function rate()
    {
        return $this->belongsTo(Rate::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stay()
    {
        return $this->hasOne(Stay::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
