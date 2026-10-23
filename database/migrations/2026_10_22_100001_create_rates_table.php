<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('hours');
            $table->decimal('price', 10, 2);
            $table->decimal('extra_hour_price', 10, 2)->default(0);
            $table->string('room_type')->nullable(); // null = aplica a todos los tipos
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rates');
    }
};
