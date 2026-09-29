<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_cierre_calcula_la_diferencia_correctamente(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);

        $shift = Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_amount' => 20,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/shifts/close', [
            'counted_amount' => 15,
        ]);

        // Sin ventas ni gastos: esperado = 20, diferencia = 15 - 20 = -5
        $response->assertStatus(200)
            ->assertJsonPath('shift.expected_amount', '20.00')
            ->assertJsonPath('shift.difference', '-5.00');

        $this->assertNotNull($shift->fresh()->closed_at);
    }

    public function test_no_se_puede_cerrar_un_turno_ya_cerrado(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);

        Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
            'closed_at' => now(),
            'opening_amount' => 20,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/shifts/close', [
            'counted_amount' => 20,
        ]);

        $response->assertStatus(404);
    }
}
