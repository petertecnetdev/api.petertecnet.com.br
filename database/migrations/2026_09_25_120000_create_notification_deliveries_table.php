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
            $table->string('destination', 64)->nullable();
            $table->text('destination_e164')->nullable();
            $table->string('provider', 64);
            $table->string('provider_message_id', 191)->nullable()->unique();
            $table->string('template_key', 96)->nullable()->index();
            $table->string('template_name', 191)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('status', 32)->default('QUEUED')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('error_code', 96)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
