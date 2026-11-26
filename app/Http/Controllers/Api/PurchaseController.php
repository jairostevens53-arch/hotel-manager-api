<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shift;
use App\Models\InventoryMovement;
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

        $shift = Shift::where('user_id', $request->user()->id)
            ->whereNull('closed_at')
            ->first();

        $purchase = DB::transaction(function () use ($data, $request, $shift) {
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
                'shift_id' => $shift?->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'user_id' => $request->user()->id,
                'total' => round($total, 2),
            ]);

            $purchase->items()->createMany($lines);

            // Sube el stock de cada producto comprado
            foreach ($lines as $line) {
                Product::whereKey($line['product_id'])->increment('stock', $line['quantity']);
                InventoryMovement::create([
                    'product_id' => $line['product_id'],
                    'user_id' => $request->user()->id,
                    'type' => 'entrada_compra',
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'reason' => 'Entrada por compra a proveedor',
                    'reference_type' => 'purchase',
                    'reference_id' => $purchase->id,
                ]);
            }

            return $purchase;
        });

        return response()->json($purchase->load('supplier:id,name', 'items.product:id,name'), 201);
    }
}
