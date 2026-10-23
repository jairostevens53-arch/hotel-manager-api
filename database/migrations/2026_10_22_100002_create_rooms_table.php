<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('number', 10)->unique();
            $table->unsignedTinyInteger('floor');
            $table->string('type')->default('estandar');      // estandar | especial
            $table->unsignedTinyInteger('beds')->default(1);
            $table->string('status')->default('disponible');  // disponible | ocupada | limpieza | mantenimiento
            $table->boolean('active')->default(true);         // false = fuera de servicio
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
