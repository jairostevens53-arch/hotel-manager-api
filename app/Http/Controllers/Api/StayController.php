<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Rate;
use App\Models\Room;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Stay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StayController extends Controller
{
    public function index(Request $request)
    {
        $query = Stay::with('room:id,number,floor', 'rate:id,name', 'user:id,name')->latest('check_in_at');

        // El recepcionista solo necesita las estadías activas; el admin ve el historial.
        if (! $request->user()->isAdmin()) {
            $query->where('status', 'activa');
        } elseif ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('room_id')) {
            $query->where('room_id', $request->room_id);
        }

        return response()->json($query->paginate(20));
    }

    public function show(Stay $stay)
    {
        $stay->load('room', 'rate', 'user:id,name', 'sales.items.product:id,name');

        $paid = round((float) $stay->sales()->where('status', 'completada')->sum('total'), 2);

        return response()->json(['stay' => $stay, 'total_paid' => $paid]);
    }

    // Entrada: crea la estadía, cobra la tarifa (y los productos) y ocupa la habitación.
    public function store(Request $request)
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'rate_id' => ['required', 'integer', 'exists:rates,id'],
            'guest_name' => ['required', 'string', 'max:150'],
            'guest_document' => ['nullable', 'string', 'max:50'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:10'],
            'payment_method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $shift = $this->openShift($user->id);

        if (! $shift) {
            return response()->json(['message' => 'Debes abrir un turno antes de registrar una estadía.'], 409);
        }

        return DB::transaction(function () use ($data, $user, $shift) {
            // Bloquea la habitación para que dos recepcionistas no la ocupen a la vez
            $room = Room::whereKey($data['room_id'])->lockForUpdate()->first();

            if (! $room->active || $room->status !== 'disponible') {
                return response()->json(['message' => "La habitación {$room->number} no está disponible."], 409);
            }

            $rate = Rate::findOrFail($data['rate_id']);

            if (! $rate->active || ($rate->room_type && $rate->room_type !== $room->type)) {
                return response()->json(['message' => 'La tarifa elegida no aplica a esta habitación.'], 422);
            }

            [$lines, $productsTotal] = $this->consumeProducts($data['items'] ?? []);

            // El precio de la habitación SIEMPRE sale de la tarifa guardada, nunca del cliente
            $roomAmount = round((float) $rate->price, 2);
            $now = now();

            $stay = Stay::create([
                'room_id' => $room->id,
                'rate_id' => $rate->id,
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'guest_name' => $data['guest_name'],
                'guest_document' => $data['guest_document'] ?? null,
                'guests' => $data['guests'] ?? 1,
                'check_in_at' => $now,
                'expected_checkout_at' => $now->copy()->addHours($rate->hours),
                'status' => 'activa',
                'notes' => $data['notes'] ?? null,
            ]);

            $sale = Sale::create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'stay_id' => $stay->id,
                'total' => round($productsTotal + $roomAmount, 2),
                'room_amount' => $roomAmount,
                'payment_method' => $data['payment_method'],
                'concept' => 'estancia',
                'status' => 'completada',
            ]);

            if ($lines) {
                $sale->items()->createMany($lines);
            }

            $room->update(['status' => 'ocupada']);

            return response()->json([
                'stay' => $stay->load('room', 'rate'),
                'sale' => $sale->load('items.product:id,name'),
            ], 201);
        });
    }

    // "+1 hora": alarga la salida prevista y cobra la hora extra de la tarifa.
    public function extend(Request $request, Stay $stay)
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:12'],
            'payment_method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
        ]);

        $user = $request->user();
        $shift = $this->openShift($user->id);

        if (! $shift) {
            return response()->json(['message' => 'Debes abrir un turno antes de cobrar.'], 409);
        }

        $hours = $data['hours'] ?? 1;

        return DB::transaction(function () use ($stay, $data, $user, $shift, $hours) {
            $stay = Stay::whereKey($stay->id)->lockForUpdate()->first();

            if ($stay->status !== 'activa') {
                return response()->json(['message' => 'La estadía ya no está activa.'], 409);
            }

            $unit = (float) $stay->rate->extra_hour_price;

            if ($unit <= 0) {
                return response()->json(['message' => 'La tarifa no tiene precio de hora extra configurado.'], 422);
            }

            $amount = round($unit * $hours, 2);

            $stay->update([
                'expected_checkout_at' => $stay->expected_checkout_at->copy()->addHours($hours),
                'extra_hours' => $stay->extra_hours + $hours,
            ]);

            $sale = Sale::create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'stay_id' => $stay->id,
                'total' => $amount,
                'room_amount' => $amount,
                'payment_method' => $data['payment_method'],
                'concept' => 'hora_extra',
                'status' => 'completada',
            ]);

            return response()->json(['stay' => $stay->fresh()->load('room', 'rate'), 'sale' => $sale], 201);
        });
    }

    // "Cobro adicional": un monto libre (daños, servicios, etc.) ligado a la estadía.
    public function charge(Request $request, Stay $stay)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:200'],
            'payment_method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
        ]);

        $user = $request->user();
        $shift = $this->openShift($user->id);

        if (! $shift) {
            return response()->json(['message' => 'Debes abrir un turno antes de cobrar.'], 409);
        }

        return DB::transaction(function () use ($stay, $data, $user, $shift) {
            $stay = Stay::whereKey($stay->id)->lockForUpdate()->first();

            if ($stay->status !== 'activa') {
                return response()->json(['message' => 'La estadía ya no está activa.'], 409);
            }

            $amount = round((float) $data['amount'], 2);

            $sale = Sale::create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'stay_id' => $stay->id,
                'total' => $amount,
                'room_amount' => $amount,
                'payment_method' => $data['payment_method'],
                'concept' => 'cargo_adicional',
                'status' => 'completada',
            ]);

            // Deja constancia del motivo en las notas de la estadía
            if (! empty($data['description'])) {
                $line = now()->format('d/m H:i') . ' - ' . $data['description'] . ' ($' . number_format($amount, 2) . ')';
                $stay->update(['notes' => trim(($stay->notes ? $stay->notes . "\n" : '') . $line)]);
            }

            return response()->json(['stay' => $stay->fresh(), 'sale' => $sale], 201);
        });
    }

    // Salida: finaliza la estadía y manda la habitación a limpieza.
    public function checkout(Request $request, Stay $stay)
    {
        return DB::transaction(function () use ($stay) {
            $stay = Stay::whereKey($stay->id)->lockForUpdate()->first();

            if ($stay->status !== 'activa') {
                return response()->json(['message' => 'La estadía ya fue finalizada.'], 409);
            }

            $stay->update(['status' => 'finalizada', 'checked_out_at' => now()]);
            Room::whereKey($stay->room_id)->update(['status' => 'limpieza']);

            return response()->json($stay->fresh()->load('room', 'rate'));
        });
    }

    private function openShift(int $userId): ?Shift
    {
        return Shift::where('user_id', $userId)->whereNull('closed_at')->first();
    }

    // Mismas reglas que SaleController: stock bloqueado, precio de la base de datos.
    private function consumeProducts(array $items): array
    {
        if (empty($items)) {
            return [[], 0.0];
        }

        $quantities = collect($items)
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum('quantity'));

        $products = Product::whereIn('id', $quantities->keys())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $total = 0.0;
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

        return [$lines, round($total, 2)];
    }
}
