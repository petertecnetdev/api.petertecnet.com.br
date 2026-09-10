<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('application', 80)->index();
            $table->string('plan_code', 80);
            $table->string('plan_name', 160);
            $table->unsignedBigInteger('price_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('billing_interval', 32)->default('month');
            $table->unsignedSmallInteger('billing_interval_count')->default(1);
            $table->string('status', 32)->default('created')->index();
            $table->string('source', 120)->nullable()->index();
            $table->string('handoff_channel', 40)->nullable();
            $table->string('idempotency_key', 120);
            $table->json('metadata')->nullable();
            $table->timestamp('checkout_started_at')->nullable();
            $table->timestamp('payment_pending_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'application', 'idempotency_key'], 'subscription_intents_user_app_idempotency_unique');
            $table->index(['application', 'plan_code', 'status'], 'subscription_intents_funnel_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_intents');
    }
};
