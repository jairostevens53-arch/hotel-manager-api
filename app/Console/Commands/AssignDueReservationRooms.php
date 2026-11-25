<?php

namespace App\Console\Commands;

use App\Services\ReservationRoomAssigner;
use Illuminate\Console\Command;

class AssignDueReservationRooms extends Command
{
    protected $signature = 'reservations:assign-due-rooms';

    protected $description = 'Assign rooms to reservations starting within 24 hours';

    public function handle(ReservationRoomAssigner $assigner): int
    {
        $count = $assigner->assignDue();
        $this->info("Assigned {$count} reservation room(s).");

        return self::SUCCESS;
    }
}
