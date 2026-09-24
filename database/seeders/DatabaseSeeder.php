<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@hotel.test'],
            ['name' => 'Administrador', 'password' => 'admin1234', 'role' => 'admin', 'active' => true]
        );

        User::updateOrCreate(
            ['email' => 'recepcion@hotel.test'],
            ['name' => 'Recepcionista', 'password' => 'recep1234', 'role' => 'recepcionista', 'active' => true]
        );

        $bebidas = Category::firstOrCreate(['name' => 'Bebidas']);

        Product::firstOrCreate(
            ['name' => 'Agua 500ml'],
            ['category_id' => $bebidas->id, 'price' => 1.50, 'stock' => 50, 'min_stock' => 5]
        );
    }
}
