<?php

namespace Database\Seeders;

use App\Models\Rate;
use App\Models\Room;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        // Tarifas del prototipo. El precio por hora extra es provisional: ajústalo en el panel.
        $rates = [
            ['name' => '6 horas',   'hours' => 6,  'price' => 12.00, 'extra_hour_price' => 2.00],
            ['name' => 'Por noche', 'hours' => 12, 'price' => 15.00, 'extra_hour_price' => 2.00],
            ['name' => '24 horas',  'hours' => 24, 'price' => 24.00, 'extra_hour_price' => 2.00],
        ];

        foreach ($rates as $rate) {
            Rate::firstOrCreate(['name' => $rate['name']], $rate);
        }

        // Habitaciones de ejemplo (piso 1 y piso 2 del prototipo).
        $rooms = [
            ['A1', 1, 'estandar', 1],
            ['A2', 1, 'estandar', 2],
            ['A3', 1, 'especial', 3],
            ['B1', 2, 'estandar', 1],
            ['B2', 2, 'estandar', 2],
            ['B3', 2, 'especial', 3],
        ];

        foreach ($rooms as [$number, $floor, $type, $beds]) {
            Room::firstOrCreate(
                ['number' => $number],
                ['floor' => $floor, 'type' => $type, 'beds' => $beds, 'status' => 'disponible']
            );
        }
    }
}
