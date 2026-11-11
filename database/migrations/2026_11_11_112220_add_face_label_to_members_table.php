<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Nama kelas di model YOLO (mis. "Firdo-Al-Farobbi"). Unik: satu wajah = satu member.
            $table->string('face_label')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['face_label']);
            $table->dropColumn('face_label');
        });
    }
};