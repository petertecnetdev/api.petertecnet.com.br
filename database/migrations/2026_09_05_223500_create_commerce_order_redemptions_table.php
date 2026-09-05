<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_order_redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('event_id')->index();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('redeemed_by_user_id')->nullable()->index();
            $table->timestamp('redeemed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'order_id'], 'commerce_redemptions_app_order_unique');
            $table->index(['app_id', 'event_id', 'status'], 'commerce_redemptions_event_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_redemptions');
    }
};
