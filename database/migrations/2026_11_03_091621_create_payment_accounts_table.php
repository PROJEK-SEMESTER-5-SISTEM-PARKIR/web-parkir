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
    Schema::create('payment_accounts', function (Blueprint $table) {
        $table->id();
        $table->string('type', 10);                    // bank | qris
        $table->string('label', 60);                   // nama bank / "QRIS"
        $table->string('account_number', 30)->nullable();
        $table->string('account_name', 100)->nullable();
        $table->string('qris_path')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('payment_accounts');
}
};