<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_payment_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedInteger('pending_orders')->default(0);
            $table->decimal('pending_volume', 14, 2)->default(0);
            $table->unsignedInteger('critical_orders')->default(0);
            $table->decimal('critical_volume', 14, 2)->default(0);
            $table->unsignedInteger('expired_orders')->default(0);
            $table->unsignedInteger('provider_pending_payments')->default(0);
            $table->decimal('at_risk_volume', 14, 2)->default(0);
            $table->string('risk_level', 20)->default('healthy');
            $table->unsignedInteger('oldest_pending_age_minutes')->nullable();
            $table->timestamp('measured_at')->index();
            $table->timestamps();
            $table->index(['app_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payment_health_snapshots');
    }
};
