<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_payment_health_incidents', function (Blueprint $table) {
            $table->unsignedInteger('recovered_paid_orders')->default(0)->after('peak_provider_pending_payments');
            $table->unsignedInteger('recovered_fulfillments')->default(0)->after('recovered_paid_orders');
            $table->unsignedInteger('recovered_delivery_retries')->default(0)->after('recovered_fulfillments');
            $table->decimal('recovered_gmv', 14, 2)->default(0)->after('recovered_delivery_retries');
            $table->timestamp('last_recovery_at')->nullable()->after('recovered_gmv');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_payment_health_incidents', function (Blueprint $table) {
            $table->dropColumn([
                'recovered_paid_orders',
                'recovered_fulfillments',
                'recovered_delivery_retries',
                'recovered_gmv',
                'last_recovery_at',
            ]);
        });
    }
};
