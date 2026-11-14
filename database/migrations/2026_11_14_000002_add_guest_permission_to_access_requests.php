<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('guest_access_permission_id')
                ->nullable()
                ->after('reason');

            $table->foreign('guest_access_permission_id')
                ->references('id')
                ->on('guest_access_permissions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access_requests', function (Blueprint $table) {
            $table->dropForeign(['guest_access_permission_id']);
            $table->dropColumn('guest_access_permission_id');
        });
    }
};
