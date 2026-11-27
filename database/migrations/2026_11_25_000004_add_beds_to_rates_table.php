<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Earlier releases may already have added this column before this
        // migration was recorded. Keep the deployment safe for that database.
        if (! Schema::hasColumn('rates', 'beds')) {
            Schema::table('rates', function (Blueprint $table) {
                $table->unsignedSmallInteger('beds')->nullable()->after('room_type');
            });
        }

        $hasLookupIndex = collect(Schema::getIndexes('rates'))->contains(
            fn (array $index) => ($index['columns'] ?? []) === ['room_type', 'beds', 'active']
        );
        if (! $hasLookupIndex) {
            Schema::table('rates', function (Blueprint $table) {
                $table->index(['room_type', 'beds', 'active']);
            });
        }

        // Solo se puede inferir con seguridad la capacidad de tarifas estándar.
        DB::table('rates')->where('room_type', 'estandar')->whereNull('beds')->update(['beds' => 1]);
    }

    public function down(): void
    {
        // Keep the beds column: it may have been created by an earlier migration.
    }
};
