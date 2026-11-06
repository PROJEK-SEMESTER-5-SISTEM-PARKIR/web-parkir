<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_payments', function (Blueprint $table) {
            // langganan = pembayaran paket; tambah_kendaraan = prorata kendaraan baru di tengah periode
            $table->string('type', 20)->default('langganan')->after('method');
            $table->unsignedBigInteger('vehicle_id')->nullable()->after('type');

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::table('member_payments', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'vehicle_id']);
        });
    }
};