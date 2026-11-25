<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Support\Facades\DB;

class ReservationRoomAssigner
{
    /** Assign compatible rooms to reservations starting within the next day. */
    public function assignDue(): int
    {
        Reservation::query()
            ->whereIn('status', ['pendiente', 'confirmada'])
            ->where('expected_checkout_at', '<=', now())
            ->update(['status' => 'vencida']);

        $reservationIds = Reservation::query()
            ->whereNull('room_id')
            ->whereIn('status', ['pendiente', 'confirmada'])
            ->where('check_in_at', '<=', now()->addDay())
            ->where('expected_checkout_at', '>', now())
            ->orderBy('check_in_at')
            ->pluck('id');

        $assigned = 0;

        foreach ($reservationIds as $reservationId) {
            $wasAssigned = DB::transaction(function () use ($reservationId) {
                $reservation = Reservation::query()
                    ->lockForUpdate()
                    ->find($reservationId);

                if (! $reservation || $reservation->room_id || ! in_array($reservation->status, ['pendiente', 'confirmada'], true)) {
                    return false;
                }

                $room = Room::query()
                    ->where('active', true)
                    ->where('type', $reservation->room_type)
                    ->where('beds', $reservation->beds)
                    ->where('status', '!=', 'mantenimiento')
                    ->orderBy('floor')
                    ->orderBy('number')
                    ->lockForUpdate()
                    ->get()
                    ->first(function (Room $candidate) use ($reservation) {
                        $overlappingReservation = Reservation::query()
                            ->where('room_id', $candidate->id)
                            ->whereIn('status', ['pendiente', 'confirmada'])
                            ->where('id', '!=', $reservation->id)
                            ->where('check_in_at', '<', $reservation->expected_checkout_at)
                            ->where('expected_checkout_at', '>', $reservation->check_in_at)
                            ->exists();

                        $overlappingStay = Stay::query()
                            ->where('room_id', $candidate->id)
                            ->where('status', 'activa')
                            ->where('check_in_at', '<', $reservation->expected_checkout_at)
                            ->where('expected_checkout_at', '>', $reservation->check_in_at)
                            ->exists();

                        return ! $overlappingReservation && ! $overlappingStay;
                    });

                if (! $room) {
                    return false;
                }

                $reservation->update([
                    'room_id' => $room->id,
                    'status' => 'confirmada',
                ]);

                return true;
            });

            if ($wasAssigned) {
                $assigned++;
            }
        }

        return $assigned;
    }
}
