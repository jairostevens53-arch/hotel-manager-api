<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::with('items.product:id,name')->latest();

        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->filled('shift_id')) {
            $query->where('shift_id', $request->shift_id);
        }

        return response()->json($query->paginate(20));
    }

    public function show(Request $request, Sale $sale)
    {
        if (! $request->user()->isAdmin() && $sale->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No tienes permiso para ver esta venta.'], 403);
        }

        return response()->json($sale->load('items.product:id,name'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();

        $shift = Shift::where('user_id', $user->id)->whereNull('closed_at')->first();

        if (! $shift) {
            return response()->json(['message' => 'Debes abrir un turno antes de vender.'], 409);
        }

        $sale = DB::transaction(function () use ($data, $user, $shift) {
            // Junta líneas repetidas del mismo producto
            $quantities = collect($data['items'])
                ->groupBy('product_id')
                ->map(fn ($rows) => $rows->sum('quantity'));

            // Bloquea las filas para que dos ventas simultáneas no descuenten el mismo stock
            $products = Product::whereIn('id', $quantities->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $total = 0;
            $lines = [];

            foreach ($quantities as $productId => $quantity) {
                $product = $products[$productId];

                if (! $product->active) {
                    throw ValidationException::withMessages([
                        'items' => ["El producto {$product->name} no está disponible."],
                    ]);
                }

                if ($product->stock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Stock insuficiente de {$product->name} (disponible: {$product->stock})."],
                    ]);
                }

                // El precio SIEMPRE se toma de la base de datos, nunca del cliente
                $unitPrice = (float) $product->price;
                $subtotal = round($unitPrice * $quantity, 2);
                $total += $subtotal;

                $lines[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ];

                $product->decrement('stock', $quantity);
            }

            $sale = Sale::create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'total' => round($total, 2),
                'payment_method' => $data['payment_method'],
            ]);

            $sale->items()->createMany($lines);

            return $sale;
        });

        return response()->json($sale->load('items.product:id,name'), 201);
    }
}
