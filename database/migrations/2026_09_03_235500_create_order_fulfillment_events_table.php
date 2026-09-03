<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_fulfillment_events')) {
            return;
        }

        Schema::create('order_fulfillment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('establishment_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('event', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->string('validation_method', 30)->nullable();
            $table->string('result', 40)->default('success');
            $table->string('request_id', 120)->nullable()->index();
            $table->string('session_id_hash', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['app_id', 'establishment_id', 'created_at'], 'fulfillment_app_establishment_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_events');
    }
};
