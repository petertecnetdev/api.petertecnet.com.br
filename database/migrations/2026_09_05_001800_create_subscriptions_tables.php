<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('application_key', 80)->index();
            $table->string('provider', 40)->default('mercadopago')->index();
            $table->string('plan_key', 40)->index();
            $table->string('status', 40)->default('pending')->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('BRL');
            $table->uuid('external_reference')->unique();
            $table->string('provider_subscription_id')->nullable()->unique();
            $table->string('provider_payment_method')->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('next_payment_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'application_key', 'status'], 'subscriptions_user_app_status_idx');
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('provider_invoice_id')->nullable()->index();
            $table->string('status', 40)->index();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
    }
};
