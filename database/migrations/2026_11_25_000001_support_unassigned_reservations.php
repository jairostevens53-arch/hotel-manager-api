<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->change();
            $table->string('room_type', 24)->default('estandar')->after('rate_id');
            $table->unsignedTinyInteger('beds')->default(1)->after('room_type');
            $table->unsignedSmallInteger('rate_units')->default(1)->after('beds');
        });
    }

    public function down(): void
    {
        if (DB::table('reservations')->whereNull('room_id')->exists()) {
            throw new RuntimeException(
                'Cannot require room_id while unassigned reservations exist.'
            );
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['room_type', 'beds', 'rate_units']);
            $table->foreignId('room_id')->nullable(false)->change();
        });
    }
};
