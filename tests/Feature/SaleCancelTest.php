<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleCancelTest extends TestCase
{
    use RefreshDatabase;

    private function crearProductoYTurno(User $user, int $stock = 10): Product
    {
        $categoria = Category::create(['name' => 'Bebidas']);

        $producto = Product::create([
            'category_id' => $categoria->id,
            'name' => 'Agua 500ml',
            'price' => 1.5,
            'stock' => $stock,
        ]);

        Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_amount' => 20,
        ]);

        return $producto;
    }

    public function test_anular_una_venta_devuelve_el_stock(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);
        $producto = $this->crearProductoYTurno($user, stock: 10);

        $venta = $this->actingAs($user, 'sanctum')->postJson('/api/sales', [
            'payment_method' => 'efectivo',
            'items' => [['product_id' => $producto->id, 'quantity' => 5]],
        ])->json();

        $this->assertEquals(5, $producto->fresh()->stock);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/sales/{$venta['id']}/cancel");

        $response->assertStatus(200)->assertJsonPath('status', 'anulada');
        $this->assertEquals(10, $producto->fresh()->stock);
    }

    public function test_no_se_puede_anular_dos_veces(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);
        $producto = $this->crearProductoYTurno($user, stock: 10);

        $venta = $this->actingAs($user, 'sanctum')->postJson('/api/sales', [
            'payment_method' => 'efectivo',
            'items' => [['product_id' => $producto->id, 'quantity' => 5]],
        ])->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/sales/{$venta['id']}/cancel");

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/sales/{$venta['id']}/cancel");

        $response->assertStatus(409);
    }

    public function test_anular_una_venta_inexistente_da_404(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/sales/9999/cancel');

        $response->assertStatus(404);
    }
}
