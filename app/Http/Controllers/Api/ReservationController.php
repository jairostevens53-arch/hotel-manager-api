<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rate;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\Shift;
use App\Services\ReservationRoomAssigner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        app(ReservationRoomAssigner::class)->assignDue();

        $query = Reservation::withSum([
            'sales as paid_amount' => fn ($sales) => $sales->where('status', 'completada'),
        ], 'room_amount')
            ->with('guest:id,name,document,phone,email', 'room:id,number,floor,type,beds', 'rate:id,name,price,hours,room_type,beds', 'creator:id,name')
            ->latest('check_in_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('room_id')) {
            $query->where('room_id', $request->query('room_id'));
        }
        if ($request->filled('from')) {
            $query->where('check_in_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('check_in_at', '<', $request->query('to'));
        }

        $reservations = $query->paginate(max(1, min((int) $request->query('per_page', 20), 100)));
        $reservations->getCollection()->transform(fn ($reservation) => $this->paymentSummary($reservation));

        return response()->json($reservations);
    }

    public function show(Reservation $reservation)
    {
        return response()->json($this->paymentSummary(
            $reservation->load('guest', 'room', 'rate', 'creator:id,name', 'stay')
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'guest_id' => ['required', 'integer', 'exists:guests,id'],
            'room_type' => ['required', Rule::in(['estandar', 'especial'])],
            'beds' => ['required', 'integer', 'min:1', 'max:10'],
            'rate_id' => ['required', 'integer', 'exists:rates,id'],
            'rate_units' => ['nullable', 'integer', 'min:1', 'max:30'],
            'check_in_at' => ['required', 'date', 'after_or_equal:now'],
            'expected_checkout_at' => ['required', 'date', 'after:check_in_at'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:10'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // La API recibe instantes ISO (normalmente UTC), pero las columnas
        // dateTime se guardan como hora local del hotel sin zona. Convertir
        // antes de persistir evita desplazar la reserva al día siguiente.
        foreach (['check_in_at', 'expected_checkout_at'] as $field) {
            $data[$field] = Carbon::parse($data[$field])
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        }

        $reservation = DB::transaction(function () use ($data, $request) {
            $guest = Guest::whereKey($data['guest_id'])->lockForUpdate()->first();
            $rate = Rate::query()->findOrFail($data['rate_id']);

            if (! $guest) {
                throw ValidationException::withMessages(['guest_id' => ['El huésped no existe o está archivado.']]);
            }
            if (blank($guest->document) || blank($guest->phone)) {
                throw ValidationException::withMessages([
                    'guest_id' => ['Para crear una reserva, el huésped debe tener documento y teléfono registrados.'],
                ]);
            }

            $expectedRateBeds = $data['room_type'] === 'estandar' ? 1 : $data['beds'];
            if (! $rate->active || $rate->room_type !== $data['room_type'] || (int) $rate->beds !== $expectedRateBeds) {
                throw ValidationException::withMessages(['rate_id' => ['La tarifa no aplica a esta habitación.']]);
            }
            if ($data['room_type'] === 'estandar' && (int) $data['beds'] !== 1) {
                throw ValidationException::withMessages(['beds' => ['Las habitaciones comunes tienen una cama.']]);
            }
            if (($data['guests'] ?? 1) > $data['beds']) {
                throw ValidationException::withMessages(['guests' => ['La cantidad de huéspedes supera las camas solicitadas.']]);
            }

            return Reservation::create([
                ...$data,
                'guests' => $data['guests'] ?? 1,
                'rate_units' => $data['rate_units'] ?? 1,
                'agreed_price' => round((float) $rate->price * ($data['rate_units'] ?? 1), 2),
                'created_by' => $request->user()->id,
                'status' => 'pendiente',
            ]);
        });

        app(ReservationRoomAssigner::class)->assignDue();

        return response()->json($this->paymentSummary(
            $reservation->fresh()->load('guest', 'room', 'rate', 'creator:id,name')
        ), 201);
    }

    public function payment(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
        ]);

        $user = $request->user();
        $shift = Shift::where('user_id', $user->id)->whereNull('closed_at')->first();
        if (! $shift) {
            return response()->json(['message' => 'Debes abrir un turno antes de registrar un abono.'], 409);
        }

        return DB::transaction(function () use ($reservation, $data, $user, $shift) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (! in_array($reservation->status, ['pendiente', 'confirmada'], true)) {
                return response()->json(['message' => 'Solo se pueden abonar reservas pendientes o confirmadas.'], 409);
            }

            $paid = (float) $reservation->sales()->where('status', 'completada')->sum('room_amount');
            $balance = max(0, round((float) $reservation->agreed_price - $paid, 2));
            $amount = round((float) $data['amount'], 2);
            if ($amount > $balance) {
                return response()->json(['message' => 'El abono supera el saldo pendiente de la reserva.'], 422);
            }

            Sale::create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'reservation_id' => $reservation->id,
                'total' => $amount,
                'room_amount' => $amount,
                'payment_method' => $data['payment_method'],
                'concept' => 'abono_reserva',
                'status' => 'completada',
            ]);

            return response()->json($this->paymentSummary(
                $reservation->fresh()->load('guest', 'room', 'rate')
            ), 201);
        });
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        if (! in_array($reservation->status, ['pendiente', 'confirmada'], true)) {
            return response()->json(['message' => 'Solo puedes cancelar una reserva pendiente o confirmada.'], 409);
        }

        $reservation->update(['status' => 'cancelada']);

        return response()->json($reservation->fresh()->load('guest', 'room', 'rate'));
    }

    public function checkIn(Request $request, Reservation $reservation, StayController $stayController)
    {
        app(ReservationRoomAssigner::class)->assignDue();
        $reservation->refresh();

        if (! $reservation->room_id) {
            return response()->json(['message' => 'Todavía no se ha asignado una habitación a esta reserva.'], 409);
        }
        if ($reservation->check_in_at->isFuture()) {
            return response()->json(['message' => 'El check-in solo puede registrarse desde la fecha de entrada.'], 409);
        }
        if ($reservation->status !== 'confirmada') {
            return response()->json(['message' => 'La reserva no está confirmada o ya fue procesada.'], 409);
        }

        $request->merge(['reservation_id' => $reservation->id]);

        return $stayController->store($request);
    }

    private function paymentSummary(Reservation $reservation): Reservation
    {
        $paid = $reservation->getAttribute('paid_amount');
        if ($paid === null) {
            $paid = $reservation->sales()->where('status', 'completada')->sum('room_amount');
        }
        $paid = round((float) $paid, 2);
        $reservation->setAttribute('paid_amount', $paid);
        $reservation->setAttribute(
            'balance',
            max(0, round((float) $reservation->agreed_price - $paid, 2))
        );

        return $reservation;
    }
}
