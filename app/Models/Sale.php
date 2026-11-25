<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'shift_id', 'user_id', 'stay_id', 'reservation_id', 'total', 'room_amount',
        'payment_method', 'concept', 'status',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function stay()
    {
        return $this->belongsTo(Stay::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }
}
