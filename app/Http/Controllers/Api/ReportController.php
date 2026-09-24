<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function dashboard(Request $request)
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $days = (int) ($data['days'] ?? 7);
        $from = now()->subDays($days - 1)->startOfDay();

        $sales = Sale::where('status', 'completada')->where('created_at', '>=', $from);

        // Ventas por día (con ceros en los días sin ventas, para la gráfica de líneas)
        $byDay = (clone $sales)
            ->selectRaw('DATE(created_at) as day, SUM(total) as total, COUNT(*) as count')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $salesByDay = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $row = $byDay->get($day);

            $salesByDay[] = [
                'date' => $day,
                'total' => round((float) ($row->total ?? 0), 2),
                'count' => (int) ($row->count ?? 0),
            ];
        }

        // Ventas por método de pago (para la gráfica circular)
        $byMethod = (clone $sales)
            ->selectRaw('payment_method, SUM(total) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method')
            ->map(fn ($v) => round((float) $v, 2));

        // Productos más vendidos
        $topProducts = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completada')
            ->where('sales.created_at', '>=', $from)
            ->selectRaw('products.id, products.name, SUM(sale_items.quantity) as quantity, SUM(sale_items.subtotal) as revenue')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'days' => $days],
            'totals' => [
                'sales' => round((float) (clone $sales)->sum('total'), 2),
                'sales_count' => (clone $sales)->count(),
                'expenses' => round((float) Expense::where('created_at', '>=', $from)->sum('amount'), 2),
                'purchases' => round((float) Purchase::where('created_at', '>=', $from)->sum('total'), 2),
            ],
            'sales_by_day' => $salesByDay,
            'sales_by_method' => $byMethod,
            'top_products' => $topProducts,
            'low_stock' => Product::where('active', true)
                ->whereColumn('stock', '<=', 'min_stock')
                ->orderBy('stock')
                ->get(['id', 'name', 'stock', 'min_stock']),
            'open_shifts' => Shift::with('user:id,name')
                ->whereNull('closed_at')
                ->get(['id', 'user_id', 'opened_at', 'opening_amount']),
        ]);
    }
}
