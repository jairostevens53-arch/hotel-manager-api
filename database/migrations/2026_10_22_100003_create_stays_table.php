<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('rate_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('guest_name');
            $table->string('guest_document')->nullable();
            $table->unsignedTinyInteger('guests')->default(1);
            $table->dateTime('check_in_at');
            $table->dateTime('expected_checkout_at');
            $table->dateTime('checked_out_at')->nullable();
            $table->unsignedSmallInteger('extra_hours')->default(0);
            $table->string('status')->default('activa');      // activa | finalizada | cancelada
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};
