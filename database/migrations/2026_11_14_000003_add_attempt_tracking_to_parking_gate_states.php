<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parking_gate_states', function (Blueprint $table) {
            // Jumlah percobaan verifikasi dalam satu sesi gerbang (maks 5)
            $table->tinyInteger('attempt_count')->default(0)->after('plate_detected');
            // Jumlah kegagalan kualitas (gambar gelap/buram) dalam satu sesi (maks 3 lalu dihitung percobaan)
            $table->tinyInteger('quality_fail_count')->default(0)->after('attempt_count');
            // Alasan kegagalan terakhir untuk ditampilkan di LCD
            $table->string('last_failure_reason')->nullable()->after('quality_fail_count');
            // Akumulasi suara pengenalan wajah (JSON): {member_id: count} untuk voting 3/5
            $table->json('face_votes')->nullable()->after('last_failure_reason');
            // Total frame wajah yang dikirim dalam sesi ini (untuk logika 5-frame voting)
            $table->tinyInteger('face_frame_count')->default(0)->after('face_votes');
        });
    }

    public function down(): void
    {
        Schema::table('parking_gate_states', function (Blueprint $table) {
            $table->dropColumn([
                'attempt_count',
                'quality_fail_count',
                'last_failure_reason',
                'face_votes',
                'face_frame_count',
            ]);
        });
    }
};
