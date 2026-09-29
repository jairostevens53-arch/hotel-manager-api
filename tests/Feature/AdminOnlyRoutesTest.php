<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOnlyRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_recepcionista_no_puede_comprar(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/purchases', [
            'items' => [['product_id' => 1, 'quantity' => 1, 'unit_cost' => 1]],
        ]);

        $response->assertStatus(403);
    }

    public function test_recepcionista_no_puede_ver_el_dashboard(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/dashboard')
            ->assertStatus(403);
    }

    public function test_recepcionista_no_puede_ver_proveedores(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/suppliers')
            ->assertStatus(403);
    }

    public function test_admin_si_puede_ver_el_dashboard(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/dashboard')
            ->assertStatus(200);
    }
}
