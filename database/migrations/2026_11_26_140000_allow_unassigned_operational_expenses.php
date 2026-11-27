<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('expenses')->whereNull('shift_id')->exists()) {
            throw new RuntimeException('Cannot require a shift while standalone expenses exist.');
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable(false)->change();
        });
    }
};
