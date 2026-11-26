<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\InventoryMovement;
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
        $query = Sale::with('items.product:id,name', 'user:id,name', 'guest:id,name,document,phone,email', 'stay.room:id,number')->latest();

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

        return response()->json($sale->load('items.product:id,name', 'user:id,name', 'guest:id,name,document,phone,email', 'stay.room:id,number'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'guest_id' => ['nullable', 'integer', 'exists:guests,id'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_document' => ['nullable', 'string', 'max:50'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();

        $shift = Shift::where('user_id', $user->id)->whereNull('closed_at')->first();

        if (! $shift) {
            return response()->json(['message' => 'Debes abrir un turno antes de vender.'], 409);
        }

        $guest = ! empty($data['guest_id']) ? Guest::findOrFail($data['guest_id']) : null;
        $customer = [
            'guest_id' => $guest?->id,
            'customer_name' => $guest?->name ?? ($data['customer_name'] ?? null),
            'customer_document' => $guest?->document ?? ($data['customer_document'] ?? null),
            'customer_phone' => $guest?->phone ?? ($data['customer_phone'] ?? null),
        ];

        $sale = DB::transaction(function () use ($data, $user, $shift, $customer) {
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
                ...$customer,
                'total' => round($total, 2),
                'payment_method' => $data['payment_method'],
                'concept' => 'productos',
            ]);

            $sale->items()->createMany($lines);

            foreach ($lines as $line) {
                InventoryMovement::create([
                    'product_id' => $line['product_id'],
                    'user_id' => $user->id,
                    'type' => 'salida_venta',
                    'quantity' => -$line['quantity'],
                    'reason' => 'Venta de producto',
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id,
                ]);
            }

            return $sale;
        });

        return response()->json($sale->load('items.product:id,name', 'guest:id,name,document,phone,email'), 201);
    }

    public function cancel(Request $request, Sale $sale)
    {
        if (! $request->user()->isAdmin() && $sale->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No tienes permiso para anular esta venta.'], 403);
        }

        if ($sale->status === 'anulada') {
            return response()->json(['message' => 'Esta venta ya está anulada.'], 409);
        }

        if ($sale->shift->closed_at) {
            return response()->json(['message' => 'No se puede anular una venta de un turno ya cerrado.'], 409);
        }

        DB::transaction(function () use ($sale, $request) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->with('items')->firstOrFail();

            if ($sale->status === 'anulada') {
                return;
            }

            foreach ($sale->items as $item) {
                Product::whereKey($item->product_id)->increment('stock', $item->quantity);
                InventoryMovement::create([
                    'product_id' => $item->product_id,
                    'user_id' => $request->user()->id,
                    'type' => 'entrada_anulacion',
                    'quantity' => $item->quantity,
                    'reason' => 'Reversión por anulación de venta',
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id,
                ]);
            }

            $sale->update(['status' => 'anulada']);
        });

        return response()->json($sale->fresh()->load('items.product:id,name'));
    }
}
