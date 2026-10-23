<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stay extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id', 'rate_id', 'shift_id', 'user_id',
        'guest_name', 'guest_document', 'guests',
        'check_in_at', 'expected_checkout_at', 'checked_out_at',
        'extra_hours', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'guests' => 'integer',
            'extra_hours' => 'integer',
            'check_in_at' => 'datetime',
            'expected_checkout_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function rate()
    {
        return $this->belongsTo(Rate::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
