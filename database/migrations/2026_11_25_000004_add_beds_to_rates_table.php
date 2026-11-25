<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rates', function (Blueprint $table) {
            $table->unsignedSmallInteger('beds')->nullable()->after('room_type');
            $table->index(['room_type', 'beds', 'active']);
        });

        // Solo se puede inferir con seguridad la capacidad de tarifas estándar.
        DB::table('rates')->where('room_type', 'estandar')->update(['beds' => 1]);
    }

    public function down(): void
    {
        Schema::table('rates', function (Blueprint $table) {
            $table->dropIndex(['room_type', 'beds', 'active']);
            $table->dropColumn('beds');
        });
    }
};
