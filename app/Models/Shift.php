<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'opened_at', 'closed_at', 'opening_amount',
        'expected_amount', 'counted_amount', 'difference', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function summary(): array
    {
        $sales = $this->sales()
            ->where('status', 'completada')
            ->selectRaw('payment_method, SUM(total) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $cashSales = (float) ($sales['efectivo'] ?? 0);
        $expenses = (float) $this->expenses()->sum('amount');

        return [
            'opening_amount' => round((float) $this->opening_amount, 2),
            'cash_sales' => round($cashSales, 2),
            'card_sales' => round((float) ($sales['tarjeta'] ?? 0), 2),
            'transfer_sales' => round((float) ($sales['transferencia'] ?? 0), 2),
            'expenses' => round($expenses, 2),
            'expected_amount' => round((float) $this->opening_amount + $cashSales - $expenses, 2),
        ];
    }
}
