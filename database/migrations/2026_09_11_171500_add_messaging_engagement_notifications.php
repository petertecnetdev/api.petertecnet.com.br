<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messaging_user_settings', function (Blueprint $table) {
            $table->boolean('email_new_messages')->default(true);
            $table->boolean('push_new_messages')->default(true);
            $table->boolean('unread_reminders')->default(true);
            $table->boolean('digest_messages')->default(true);
            $table->boolean('include_message_preview')->default(true);
            $table->unsignedSmallInteger('email_cooldown_minutes')->default(10);
            $table->unsignedSmallInteger('first_reminder_minutes')->default(120);
            $table->unsignedSmallInteger('second_reminder_minutes')->default(720);
        });

        Schema::create('messaging_engagement_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('last_sender_user_id')->nullable();
            $table->unsignedBigInteger('first_message_id');
            $table->unsignedBigInteger('last_message_id');
            $table->unsignedInteger('message_count')->default(1);
            $table->timestamp('first_message_at');
            $table->timestamp('last_message_at');
            $table->timestamp('next_email_at')->nullable()->index();
            $table->string('next_email_kind', 24)->nullable();
            $table->timestamp('last_email_at')->nullable();
            $table->unsignedSmallInteger('email_count')->default(0);
            $table->timestamp('first_push_at')->nullable();
            $table->timestamp('first_read_at')->nullable()->index();
            $table->timestamp('first_response_at')->nullable()->index();
            $table->timestamp('reminder_1_at')->nullable();
            $table->timestamp('reminder_2_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('last_sender_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('first_message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('last_message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->index(['app_id', 'user_id', 'status'], 'msg_engagement_app_user_status_idx');
            $table->index(['conversation_id', 'user_id', 'status'], 'msg_engagement_conversation_user_idx');
        });

        Schema::create('messaging_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('engagement_cycle_id')->nullable();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->string('email_status', 24)->default('pending')->index();
            $table->string('push_status', 24)->default('pending')->index();
            $table->string('skip_reason', 80)->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'user_id'], 'msg_notification_message_user_unique');
            $table->foreign('engagement_cycle_id')->references('id')->on('messaging_engagement_cycles')->nullOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['app_id', 'user_id', 'created_at'], 'msg_notification_user_created_idx');
        });

        Schema::create('messaging_email_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 24)->default('new_message');
            $table->unsignedInteger('conversation_count')->default(1);
            $table->unsignedInteger('message_count')->default(1);
            $table->json('cycle_ids');
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('clicked_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['app_id', 'user_id', 'sent_at'], 'msg_email_batch_user_sent_idx');
        });

        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 24)->default('aes128gcm');
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('disabled_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['app_id', 'user_id', 'endpoint_hash'], 'web_push_app_user_endpoint_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('messaging_email_batches');
        Schema::dropIfExists('messaging_notification_deliveries');
        Schema::dropIfExists('messaging_engagement_cycles');

        Schema::table('messaging_user_settings', function (Blueprint $table) {
            $table->dropColumn([
                'email_new_messages',
                'push_new_messages',
                'unread_reminders',
                'digest_messages',
                'include_message_preview',
                'email_cooldown_minutes',
                'first_reminder_minutes',
                'second_reminder_minutes',
            ]);
        });
    }
};
