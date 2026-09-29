<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleStockTest extends TestCase
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

    public function test_la_venta_descuenta_el_stock(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);
        $producto = $this->crearProductoYTurno($user, stock: 10);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/sales', [
            'payment_method' => 'efectivo',
            'items' => [['product_id' => $producto->id, 'quantity' => 3]],
        ]);

        $response->assertStatus(201)->assertJsonPath('total', 4.5);
        $this->assertEquals(7, $producto->fresh()->stock);
    }

    public function test_no_se_puede_vender_mas_stock_del_disponible(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);
        $producto = $this->crearProductoYTurno($user, stock: 2);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/sales', [
            'payment_method' => 'efectivo',
            'items' => [['product_id' => $producto->id, 'quantity' => 5]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(2, $producto->fresh()->stock);
    }

    public function test_no_se_puede_vender_sin_turno_abierto(): void
    {
        $user = User::factory()->create(['role' => 'recepcionista']);
        $categoria = Category::create(['name' => 'Bebidas']);
        $producto = Product::create([
            'category_id' => $categoria->id, 'name' => 'Agua', 'price' => 1.5, 'stock' => 10,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/sales', [
            'payment_method' => 'efectivo',
            'items' => [['product_id' => $producto->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(409);
    }
}
