<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Rate;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_rates_can_be_created_and_filtered_by_exact_room_type_and_beds(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin, 'sanctum');

        $ids = [];
        foreach ([['estandar', 1], ['especial', 2], ['especial', 3], ['especial', 4]] as [$type, $beds]) {
            $response = $this->postJson('/api/rates', [
                'name' => ucfirst($type) . ' ' . $beds,
                'hours' => 12,
                'price' => 40 + $beds,
                'extra_hour_price' => 2,
                'room_type' => $type,
                'beds' => $beds,
                'active' => true,
            ])->assertCreated();

            $ids[] = $response->json('id');
            $this->assertSame($type, $response->json('room_type'));
            $this->assertSame($beds, $response->json('beds'));
        }

        foreach ([['estandar', 1], ['especial', 2], ['especial', 3], ['especial', 4]] as $index => [$type, $beds]) {
            $this->getJson("/api/rates?room_type={$type}&beds={$beds}")
                ->assertOk()
                ->assertJsonCount(1)
                ->assertJsonPath('0.id', $ids[$index])
                ->assertJsonPath('0.hours', 12)
                ->assertJsonPath('0.price', number_format(41 + $index, 2, '.', ''));
        }

        $this->getJson('/api/rates?room_type=especial&beds=4')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ids[3])
            ->assertJsonPath('0.hours', 12)
            ->assertJsonPath('0.price', '44.00');

        $inactive = $this->postJson('/api/rates', [
            'name' => 'Especial 4 camas inactiva',
            'hours' => 24,
            'price' => 80,
            'extra_hour_price' => 3,
            'room_type' => 'especial',
            'beds' => 4,
            'active' => false,
        ])->assertCreated()->assertJsonPath('active', false)->json('id');

        $this->getJson('/api/rates?room_type=especial&beds=4')
            ->assertOk()
            ->assertJsonCount(1);
        $this->getJson('/api/rates?only_active=0&room_type=especial&beds=4')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $inactive]);
        $this->getJson('/api/rates?room_type=especial&beds=5')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/rates?room_type=estandar&beds=2')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('beds');
    }

    public function test_api_rejects_standard_rates_with_more_than_one_bed(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/rates', [
                'name' => 'Estándar inválida',
                'hours' => 12,
                'price' => 40,
                'extra_hour_price' => 2,
                'room_type' => 'estandar',
                'beds' => 2,
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('beds');

        $rate = Rate::create([
            'name' => 'Estándar válida',
            'hours' => 12,
            'price' => 40,
            'extra_hour_price' => 2,
            'room_type' => 'estandar',
            'beds' => 1,
            'active' => true,
        ]);

        $this->putJson('/api/rates/' . $rate->id, ['room_type' => 'estandar', 'beds' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('beds');
    }

    public function test_reservation_api_rejects_a_rate_for_a_different_room_type_or_bed_count(): void
    {
        $receptionist = User::factory()->create(['role' => 'recepcionista', 'active' => true]);
        $guest = Guest::create(['name' => 'Huésped de prueba']);
        $room = Room::create(['number' => 'T401', 'floor' => 4, 'type' => 'especial', 'beds' => 4]);
        $incompatibleRates = [
            Rate::create([
                'name' => 'Especial 3 camas',
                'hours' => 12,
                'price' => 55,
                'extra_hour_price' => 2,
                'room_type' => 'especial',
                'beds' => 3,
                'active' => true,
            ]),
            Rate::create([
                'name' => 'Estándar 1 cama',
                'hours' => 12,
                'price' => 40,
                'extra_hour_price' => 2,
                'room_type' => 'estandar',
                'beds' => 1,
                'active' => true,
            ]),
        ];

        $this->actingAs($receptionist, 'sanctum');
        foreach ($incompatibleRates as $rate) {
            $this->postJson('/api/reservations', [
                'guest_id' => $guest->id,
                'room_id' => $room->id,
                'room_type' => 'especial',
                'beds' => 2,
                'rate_id' => $rate->id,
                'check_in_at' => now()->addHour()->toIso8601String(),
                'expected_checkout_at' => now()->addHours(13)->toIso8601String(),
                'guests' => 2,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('rate_id');
        }
    }
}
