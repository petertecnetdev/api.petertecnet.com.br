<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(
                ['app_id', 'attendant_id', 'type', 'appointment_status', 'order_datetime'],
                'orders_app_attendant_appointment_availability_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_app_attendant_appointment_availability_index');
        });
    }
};
