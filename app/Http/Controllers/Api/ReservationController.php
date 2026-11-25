<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rate;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $query = Reservation::with('guest:id,name,document,phone,email', 'room:id,number,floor,type,beds', 'rate:id,name,price,hours,room_type,beds', 'creator:id,name')
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

        return response()->json($query->paginate(max(1, min((int) $request->query('per_page', 20), 100))));
    }

    public function show(Reservation $reservation)
    {
        return response()->json($reservation->load('guest', 'room', 'rate', 'creator:id,name', 'stay'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'guest_id' => ['required', 'integer', 'exists:guests,id'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'rate_id' => ['required', 'integer', 'exists:rates,id'],
            'check_in_at' => ['required', 'date', 'after_or_equal:now'],
            'expected_checkout_at' => ['required', 'date', 'after:check_in_at'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:10'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reservation = DB::transaction(function () use ($data, $request) {
            $room = Room::whereKey($data['room_id'])->lockForUpdate()->firstOrFail();
            $guest = Guest::whereKey($data['guest_id'])->lockForUpdate()->first();
            $rate = Rate::query()->findOrFail($data['rate_id']);

            if (! $guest) {
                throw ValidationException::withMessages(['guest_id' => ['El huésped no existe o está archivado.']]);
            }

            if (! $room->active) {
                throw ValidationException::withMessages(['room_id' => ['La habitación está fuera de servicio.']]);
            }
            if (($data['guests'] ?? 1) > $room->beds) {
                throw ValidationException::withMessages(['guests' => ['La cantidad de huéspedes supera la capacidad de la habitación.']]);
            }
            if (! $rate->active || ! $rate->appliesToRoom($room)) {
                throw ValidationException::withMessages(['rate_id' => ['La tarifa no aplica a esta habitación.']]);
            }

            $overlap = Reservation::where('room_id', $room->id)
                ->whereIn('status', ['pendiente', 'confirmada'])
                ->where('check_in_at', '<', $data['expected_checkout_at'])
                ->where('expected_checkout_at', '>', $data['check_in_at'])
                ->exists();

            $occupied = Stay::where('room_id', $room->id)
                ->where('status', 'activa')
                ->where('check_in_at', '<', $data['expected_checkout_at'])
                ->where('expected_checkout_at', '>', $data['check_in_at'])
                ->exists();

            if ($overlap || $occupied) {
                throw ValidationException::withMessages(['room_id' => ['La habitación ya tiene una reserva o estadía en ese horario.']]);
            }

            return Reservation::create([
                ...$data,
                'guests' => $data['guests'] ?? 1,
                'agreed_price' => $rate->price,
                'created_by' => $request->user()->id,
                'status' => 'confirmada',
            ]);
        });

        return response()->json($reservation->load('guest', 'room', 'rate', 'creator:id,name'), 201);
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
        if ($reservation->status !== 'confirmada') {
            return response()->json(['message' => 'La reserva no está confirmada o ya fue procesada.'], 409);
        }

        $request->merge(['reservation_id' => $reservation->id]);

        return $stayController->store($request);
    }
}
