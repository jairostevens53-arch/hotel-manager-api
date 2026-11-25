<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StayCharge extends Model
{
    protected $fillable = [
        'stay_id', 'user_id', 'sale_id', 'description', 'amount', 'items', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'items' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function stay()
    {
        return $this->belongsTo(Stay::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
