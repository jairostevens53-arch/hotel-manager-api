<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('stay_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->decimal('room_amount', 10, 2)->default(0)->after('total');
            $table->string('concept')->default('productos')->after('payment_method');
            // concept: productos | estancia | hora_extra | cargo_adicional
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stay_id');
            $table->dropColumn(['room_amount', 'concept']);
        });
    }
};
