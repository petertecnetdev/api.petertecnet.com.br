<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(
                ['app_id', 'provider_id', 'scheduled_at'],
                'appointments_app_provider_scheduled_index'
            );
            $table->index(
                ['app_id', 'client_id', 'scheduled_at'],
                'appointments_app_client_scheduled_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_app_provider_scheduled_index');
            $table->dropIndex('appointments_app_client_scheduled_index');
        });
    }
};
