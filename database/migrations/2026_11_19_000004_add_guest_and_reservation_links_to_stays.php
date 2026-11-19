<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->foreignId('guest_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->unique()->after('guest_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_id');
            $table->dropConstrainedForeignId('guest_id');
        });
    }
};
