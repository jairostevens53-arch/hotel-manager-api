<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('rate_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('check_in_at');
            $table->dateTime('expected_checkout_at');
            $table->unsignedTinyInteger('guests')->default(1);
            $table->decimal('agreed_price', 10, 2)->nullable();
            $table->string('status', 24)->default('confirmada')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['room_id', 'check_in_at', 'expected_checkout_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
