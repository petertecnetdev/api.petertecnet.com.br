<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('notification_id')->nullable()->index();
            $table->unsignedBigInteger('app_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('establishment_id')->nullable()->index();
            $table->string('channel', 32)->index();
            $table->string('classification', 32)->default('UTILITY')->index();
            $table->string('destination_masked', 64)->nullable();
            $table->string('provider', 32)->nullable()->index();
            $table->string('provider_message_id', 191)->nullable()->unique();
            $table->string('template', 191)->nullable()->index();
            $table->string('template_locale', 16)->nullable();
            $table->string('status', 32)->default('QUEUED')->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->uuid('correlation_id')->unique();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('app_id')->nullable()->index();
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->boolean('marketing_whatsapp_enabled')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'app_id']);
        });

        Schema::create('notification_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_key', 191)->unique();
            $table->string('provider_message_id', 191)->nullable()->index();
            $table->string('event_type', 64)->nullable();
            $table->string('payload_hash', 64);
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_webhook_events');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_deliveries');
    }
};
