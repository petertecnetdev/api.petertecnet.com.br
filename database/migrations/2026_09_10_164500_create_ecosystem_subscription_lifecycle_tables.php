<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecosystem_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscription_intent_id')->nullable()->constrained('subscription_intents')->nullOnDelete();
            $table->string('plan_code', 80);
            $table->string('status', 40)->default('active')->index();
            $table->char('currency', 3)->default('BRL');
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->string('billing_interval', 20)->default('month');
            $table->unsignedSmallInteger('billing_interval_count')->default(1);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'user_id'], 'ecosystem_subscriptions_app_user_unique');
            $table->index(['app_id', 'status', 'current_period_end'], 'ecosystem_subscriptions_status_period_idx');
        });

        Schema::create('ecosystem_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('ecosystem_subscriptions')->nullOnDelete();
            $table->string('key', 100);
            $table->string('status', 40)->default('active')->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'user_id', 'key'], 'ecosystem_entitlements_app_user_key_unique');
            $table->index(['app_id', 'key', 'status'], 'ecosystem_entitlements_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecosystem_entitlements');
        Schema::dropIfExists('ecosystem_subscriptions');
    }
};
