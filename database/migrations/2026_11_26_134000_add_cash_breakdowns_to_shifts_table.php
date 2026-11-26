<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->json('opening_breakdown')->nullable()->after('opening_amount');
            $table->json('counted_breakdown')->nullable()->after('counted_amount');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['opening_breakdown', 'counted_breakdown']);
        });
    }
};
