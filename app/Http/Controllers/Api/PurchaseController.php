<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function index()
    {
        return response()->json(
            Purchase::with('supplier:id,name', 'items.product:id,name')->latest()->paginate(20)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $purchase = DB::transaction(function () use ($data, $request) {
            $total = 0;
            $lines = [];

            foreach ($data['items'] as $item) {
                $subtotal = round($item['quantity'] * (float) $item['unit_cost'], 2);
                $total += $subtotal;

                $lines[] = [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => round((float) $item['unit_cost'], 2),
                    'subtotal' => $subtotal,
                ];
            }

            $purchase = Purchase::create([
                'supplier_id' => $data['supplier_id'] ?? null,
                'user_id' => $request->user()->id,
                'total' => round($total, 2),
            ]);

            $purchase->items()->createMany($lines);

            // Sube el stock de cada producto comprado
            foreach ($lines as $line) {
                Product::whereKey($line['product_id'])->increment('stock', $line['quantity']);
            }

            return $purchase;
        });

        return response()->json($purchase->load('supplier:id,name', 'items.product:id,name'), 201);
    }
}
