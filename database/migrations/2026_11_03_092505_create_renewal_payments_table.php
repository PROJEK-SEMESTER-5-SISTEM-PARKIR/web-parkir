<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('renewal_payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('member_id')->constrained()->cascadeOnDelete();
        $table->foreignId('subscription_plan_id')->constrained();
        $table->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();

        $table->unsignedInteger('vehicle_count');
        $table->unsignedInteger('base_amount');     // harga paket x jumlah kendaraan
        $table->unsignedSmallInteger('unique_code'); // 100-999
        $table->unsignedInteger('total_amount');     // base + kode unik

        $table->string('proof_path')->nullable();    // disk privat
        $table->string('status', 10)->default('pending'); // pending|waiting|approved|rejected|expired
        $table->string('reject_reason')->nullable();

        $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('reviewed_at')->nullable();
        $table->timestamp('expires_at');
        $table->timestamps();

        $table->index(['status', 'created_at']);
    });
}

public function down(): void
{
    Schema::dropIfExists('renewal_payments');
}
};
