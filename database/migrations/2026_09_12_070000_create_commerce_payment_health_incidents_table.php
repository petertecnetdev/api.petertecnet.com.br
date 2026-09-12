<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_payment_health_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->timestamp('started_at')->index();
            $table->timestamp('recovered_at')->nullable()->index();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('peak_at_risk_volume', 14, 2)->default(0);
            $table->unsignedInteger('peak_pending_orders')->default(0);
            $table->unsignedInteger('peak_critical_orders')->default(0);
            $table->unsignedInteger('peak_provider_pending_payments')->default(0);
            $table->unsignedInteger('closing_pending_orders')->nullable();
            $table->unsignedInteger('closing_critical_orders')->nullable();
            $table->decimal('closing_at_risk_volume', 14, 2)->nullable();
            $table->json('signals')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'started_at']);
            $table->index(['app_id', 'recovered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payment_health_incidents');
    }
};
