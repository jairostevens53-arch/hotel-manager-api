<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('reservation_id')->nullable()->after('stay_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::create('stay_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 200)->nullable();
            $table->decimal('amount', 10, 2);
            $table->json('items')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
            $table->index(['stay_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_charges');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_id');
        });
    }
};
