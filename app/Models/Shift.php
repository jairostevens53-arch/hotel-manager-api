<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'opened_at', 'closed_at', 'opening_amount',
        'opening_breakdown', 'expected_amount', 'counted_amount',
        'counted_breakdown', 'difference', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_breakdown' => 'array',
            'counted_breakdown' => 'array',
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

    public function stays()
    {
        return $this->hasMany(Stay::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
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
        $purchases = (float) $this->purchases()->sum('total');

        return [
            'opening_amount' => round((float) $this->opening_amount, 2),
            'cash_sales' => round($cashSales, 2),
            'card_sales' => round((float) ($sales['tarjeta'] ?? 0), 2),
            'transfer_sales' => round((float) ($sales['transferencia'] ?? 0), 2),
            'expenses' => round($expenses, 2),
            'inventory_purchases' => round($purchases, 2),
            'expected_amount' => round((float) $this->opening_amount + $cashSales - $expenses - $purchases, 2),
        ];
    }

    public function transactionDetails(): array
    {
        return [
            'stays' => $this->stays()
                ->with('room:id,number', 'user:id,name')
                ->orderBy('check_in_at')
                ->get(),
            'sales' => $this->sales()
                ->where('status', 'completada')
                ->with([
                    'items.product:id,name',
                    'guest:id,name,document,phone',
                    'stay.room:id,number',
                    'user:id,name',
                ])
                ->orderBy('created_at')
                ->get(),
            'expenses' => $this->expenses()
                ->with('user:id,name')
                ->orderBy('created_at')
                ->get(),
            'purchases' => $this->purchases()
                ->with('supplier:id,name', 'items.product:id,name', 'user:id,name')
                ->orderBy('created_at')
                ->get(),
        ];
    }
}
