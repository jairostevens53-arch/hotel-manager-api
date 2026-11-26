<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('guest_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('customer_name', 150)->nullable()->after('guest_id');
            $table->string('customer_document', 50)->nullable()->after('customer_name');
            $table->string('customer_phone', 40)->nullable()->after('customer_document');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_id');
            $table->dropColumn(['customer_name', 'customer_document', 'customer_phone']);
        });
    }
};
