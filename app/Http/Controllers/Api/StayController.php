<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Stay;
use App\Models\StayCharge;
use App\Models\Task;
use App\Services\CleaningTaskAssigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StayController extends Controller
{
    public function index(Request $request)
    {
        $query = Stay::with('room:id,number,floor,type,beds', 'rate:id,name,price,hours,room_type,beds', 'user:id,name', 'guest:id,name,document', 'charges')->latest('check_in_at');

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
        $stay->load('room', 'rate', 'user:id,name', 'guest:id,name,document,phone,email', 'reservation', 'sales.items.product:id,name', 'charges');

        $paid = round((float) $stay->sales()->where('status', 'completada')->sum('total'), 2);

        return response()->json(['stay' => $stay, 'total_paid' => $paid]);
    }

    // Entrada: crea la estadía, cobra la tarifa (y los productos) y ocupa la habitación.
    public function store(Request $request)
    {
        $data = $request->validate([
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'guest_id' => ['nullable', 'integer', 'exists:guests,id'],
            'room_id' => ['required_without:reservation_id', 'nullable', 'integer', 'exists:rooms,id'],
            'rate_id' => ['required_without:reservation_id', 'nullable', 'integer', 'exists:rates,id'],
            'rate_units' => ['nullable', 'integer', 'min:1', 'max:30'],
            'guest_name' => ['nullable', 'string', 'max:150'],
            'guest_document' => ['nullable', 'string', 'max:50'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:10'],
            'payment_method' => ['nullable', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ]);

        $data['reservation_id'] ??= null;
        $data['guest_id'] ??= null;

        if (! empty($data['reservation_id'])) {
            $reservation = Reservation::with('guest')->findOrFail($data['reservation_id']);

            if ($reservation->status !== 'confirmada') {
                return response()->json(['message' => 'La reserva no está confirmada o ya fue procesada.'], 409);
            }
            if ($reservation->expected_checkout_at->isPast()) {
                return response()->json(['message' => 'La reserva ya venció. Actualiza las fechas o registra una estadía nueva.'], 409);
            }

            $data = array_merge($data, [
                'room_id' => $reservation->room_id,
                'rate_id' => $reservation->rate_id,
                'guest_id' => $reservation->guest_id,
                'guest_name' => $reservation->guest->name,
                'guest_document' => $reservation->guest->document,
                'guests' => $reservation->guests,
                'reserved_checkout_at' => $reservation->expected_checkout_at,
                'reserved_price' => $reservation->agreed_price,
                'rate_units' => $reservation->rate_units,
            ]);
        } elseif (! empty($data['guest_id'])) {
            $guest = Guest::findOrFail($data['guest_id']);
            $data['guest_name'] = $guest->name;
            $data['guest_document'] = $guest->document;
        }

        $user = $request->user();
        $shift = $this->openShift($user->id);

        if (! $shift) {
            return response()->json(['message' => 'Debes abrir un turno antes de registrar una estadía.'], 409);
        }

        return DB::transaction(function () use ($data, $user, $shift) {
            $reservation = null;
            if (! empty($data['reservation_id'])) {
                $reservation = Reservation::whereKey($data['reservation_id'])->lockForUpdate()->firstOrFail();

                if ($reservation->status !== 'confirmada') {
                    return response()->json(['message' => 'La reserva ya fue procesada.'], 409);
                }
            if (! $reservation->room_id) {
                return response()->json(['message' => 'La reserva todavía no tiene habitación asignada.'], 409);
            }
            }

            // Bloquea la habitación para que dos recepcionistas no la ocupen a la vez
            $room = Room::whereKey($data['room_id'])->lockForUpdate()->first();

            if (! $room->active || $room->status !== 'disponible') {
                return response()->json(['message' => "La habitación {$room->number} no está disponible."], 409);
            }

            if (($data['guests'] ?? 1) > $room->beds) {
                return response()->json(['message' => 'La cantidad de huéspedes supera la capacidad de la habitación.'], 422);
            }

            $rate = Rate::findOrFail($data['rate_id']);

            if (! $rate->active || ! $rate->appliesToRoom($room)) {
                return response()->json(['message' => 'La tarifa elegida no aplica a esta habitación.'], 422);
            }

            $now = now();
            $rateUnits = (int) ($data['rate_units'] ?? 1);
            $checkoutAt = $data['reserved_checkout_at'] ?? $now->copy()->addHours($rate->hours * $rateUnits);
            $conflictingReservation = Reservation::where('room_id', $room->id)
                ->whereIn('status', ['pendiente', 'confirmada'])
                ->when($reservation, fn ($query) => $query->where('id', '!=', $reservation->id))
                ->where('check_in_at', '<', $checkoutAt)
                ->where('expected_checkout_at', '>', $now)
                ->exists();

            if ($conflictingReservation) {
                return response()->json(['message' => 'La habitación está reservada para otro huésped durante este horario.'], 409);
            }

            // Las reservas cobran solo su saldo al entrar; las estadías directas
            // cobran el total de la tarifa en este momento.
            $roomTotal = round((float) ($data['reserved_price'] ?? ($rate->price * $rateUnits)), 2);
            $paidBefore = $reservation
                ? (float) $reservation->sales()->where('status', 'completada')->sum('room_amount')
                : 0.0;
            $roomAmount = max(0, round($roomTotal - $paidBefore, 2));

            if (($roomAmount > 0 || ! empty($data['items'])) && empty($data['payment_method'])) {
                return response()->json(['message' => 'Selecciona el método de pago para cobrar el saldo de entrada.'], 422);
            }

            [$lines, $productsTotal] = $this->consumeProducts($data['items'] ?? []);
            $guest = ! empty($data['guest_id'])
                ? Guest::findOrFail($data['guest_id'])
                : (! empty($data['guest_name'])
                    ? Guest::create([
                        'name' => $data['guest_name'],
                        'document' => $data['guest_document'] ?? null,
                    ])
                    : null);

            $stay = Stay::create([
                'room_id' => $room->id,
                'rate_id' => $rate->id,
                'rate_units' => $rateUnits,
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'guest_id' => $guest?->id,
                'reservation_id' => $reservation?->id,
                'guest_name' => $data['guest_name'] ?? 'Cliente sin registrar',
                'guest_document' => $data['guest_document'] ?? null,
                'guests' => $data['guests'] ?? 1,
                'check_in_at' => $now,
                'expected_checkout_at' => $checkoutAt,
                'status' => 'activa',
                'notes' => $data['notes'] ?? null,
            ]);

            $saleTotal = round($productsTotal + $roomAmount, 2);
            $sale = $saleTotal > 0 ? Sale::create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'stay_id' => $stay->id,
                'reservation_id' => $reservation?->id,
                'total' => $saleTotal,
                'room_amount' => $roomAmount,
                'payment_method' => $data['payment_method'],
                'concept' => $reservation ? 'saldo_reserva' : 'estancia',
                'status' => 'completada',
            ]) : null;

            if ($lines && $sale) {
                $sale->items()->createMany($lines);
                foreach ($lines as $line) {
                    InventoryMovement::create([
                        'product_id' => $line['product_id'],
                        'user_id' => $user->id,
                        'type' => 'salida_venta',
                        'quantity' => -$line['quantity'],
                        'reason' => 'Consumo registrado al iniciar estadía',
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id,
                    ]);
                }
            }

            if ($reservation) {
                $reservation->update(['status' => 'convertida']);
            }

            $room->update(['status' => 'ocupada']);

            return response()->json([
                'stay' => $stay->load('room', 'rate'),
                'sale' => $sale?->load('items.product:id,name'),
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
            'amount' => ['nullable', 'numeric', 'gt:0', 'required_without:items'],
            'description' => ['nullable', 'string', 'max:200'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'pay_now' => ['required', 'boolean'],
            'payment_method' => ['required_if:pay_now,true', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
        ]);

        $user = $request->user();
        $shift = ! empty($data['pay_now']) ? $this->openShift($user->id) : null;

        if (! empty($data['pay_now']) && ! $shift) {
            return response()->json(['message' => 'Debes abrir un turno antes de cobrar.'], 409);
        }

        return DB::transaction(function () use ($stay, $data, $user, $shift) {
            $stay = Stay::whereKey($stay->id)->lockForUpdate()->first();

            if ($stay->status !== 'activa') {
                return response()->json(['message' => 'La estadía ya no está activa.'], 409);
            }

            [$lines, $productsTotal] = $this->consumeProducts($data['items'] ?? []);
            $amount = round((float) ($data['amount'] ?? 0) + $productsTotal, 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['El cargo debe ser mayor que cero.']]);
            }

            $sale = null;
            if (! empty($data['pay_now'])) {
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
                if ($lines) $sale->items()->createMany($lines);
            }

            $charge = StayCharge::create([
                'stay_id' => $stay->id,
                'user_id' => $user->id,
                'sale_id' => $sale?->id,
                'description' => $data['description'] ?? null,
                'amount' => $amount,
                'items' => $lines ?: null,
                'paid_at' => $sale ? now() : null,
            ]);

            foreach ($lines as $line) {
                InventoryMovement::create([
                    'product_id' => $line['product_id'],
                    'user_id' => $user->id,
                    'type' => 'salida_venta',
                    'quantity' => -$line['quantity'],
                    'reason' => 'Consumo registrado en estadía',
                    'reference_type' => $sale ? 'sale' : 'stay_charge',
                    'reference_id' => $sale?->id ?? $charge->id,
                ]);
            }

            return response()->json([
                'stay' => $stay->fresh(),
                'charge' => $charge,
                'sale' => $sale?->load('items.product:id,name'),
            ], 201);
        });
    }

    // Salida: finaliza la estadía y manda la habitación a limpieza.
    public function checkout(Request $request, Stay $stay)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
        ]);
        $user = $request->user();

        return DB::transaction(function () use ($stay, $request, $data, $user) {
            $stay = Stay::whereKey($stay->id)->lockForUpdate()->first();

            if ($stay->status !== 'activa') {
                return response()->json(['message' => 'La estadía ya fue finalizada.'], 409);
            }

            $unpaidCharges = $stay->charges()->whereNull('paid_at')->lockForUpdate()->get();
            $balance = round((float) $unpaidCharges->sum('amount'), 2);
            $sale = null;
            if ($balance > 0) {
                if (empty($data['payment_method'])) {
                    return response()->json(['message' => 'Elige el método de pago para liquidar los cargos pendientes.'], 422);
                }
                $shift = $this->openShift($user->id);
                if (! $shift) {
                    return response()->json(['message' => 'Debes abrir un turno antes de cobrar el saldo de la estadía.'], 409);
                }

                $sale = Sale::create([
                    'shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'stay_id' => $stay->id,
                    'total' => $balance,
                    'room_amount' => $balance,
                    'payment_method' => $data['payment_method'],
                    'concept' => 'cargos_estadia',
                    'status' => 'completada',
                ]);

                $items = $unpaidCharges->flatMap(fn ($charge) => $charge->items ?? [])->values()->all();
                if ($items) $sale->items()->createMany($items);
                foreach ($unpaidCharges as $charge) {
                    $charge->update(['paid_at' => now(), 'sale_id' => $sale->id]);
                }
            }

            $stay->update(['status' => 'finalizada', 'checked_out_at' => now()]);
            $room = Room::whereKey($stay->room_id)->lockForUpdate()->firstOrFail();
            $room->update(['status' => 'limpieza']);

            if (! Task::where('room_id', $room->id)->whereIn('status', ['pendiente', 'asignada', 'en_progreso'])->exists()) {
                $cleaner = app(CleaningTaskAssigner::class)->next();
                Task::create([
                    'room_id' => $room->id,
                    'requested_by' => $request->user()->id,
                    'assigned_to' => $cleaner?->id,
                    'type' => 'limpieza',
                    'status' => $cleaner ? 'asignada' : 'pendiente',
                    'priority' => 'normal',
                    'description' => 'Limpieza requerida al finalizar la estadía.',
                    'room_status_before' => 'limpieza',
                ]);
            }

            return response()->json([
                'stay' => $stay->fresh()->load('room', 'rate', 'charges'),
                'sale' => $sale?->load('items.product:id,name'),
                'settled_amount' => $balance,
            ]);
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
