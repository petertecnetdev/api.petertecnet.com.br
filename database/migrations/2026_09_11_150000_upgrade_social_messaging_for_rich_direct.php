<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('title');
            $table->json('settings')->nullable()->after('metadata');
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->string('role', 24)->default('member')->after('user_id');
            $table->string('nickname', 80)->nullable()->after('role');
            $table->timestamp('pinned_at')->nullable()->after('muted_until')->index();
            $table->string('notification_level', 24)->default('all')->after('pinned_at');
            $table->string('request_state', 24)->default('accepted')->after('notification_level')->index();
            $table->timestamp('last_delivered_at')->nullable()->after('last_read_at');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->after('reply_to_id');
            $table->timestamp('scheduled_at')->nullable()->after('edited_at')->index();
            $table->timestamp('expires_at')->nullable()->after('scheduled_at')->index();
            $table->timestamp('pinned_at')->nullable()->after('expires_at')->index();
            $table->timestamp('delivered_at')->nullable()->after('pinned_at');
            $table->unique(['conversation_id', 'client_uuid'], 'messages_conversation_client_uuid_unique');
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->uuid('uuid')->unique();
            $table->string('kind', 24)->index();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120);
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path', 500);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->index(['message_id', 'kind'], 'message_attachments_message_kind_idx');
        });

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->string('emoji', 32);
            $table->timestamps();
            $table->unique(['message_id', 'user_id', 'emoji'], 'message_reaction_unique');
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('message_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'user_id'], 'message_receipt_unique');
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'read_at'], 'message_receipts_user_read_idx');
        });

        Schema::create('message_pins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('pinned_by');
            $table->timestamps();
            $table->unique(['conversation_id', 'message_id'], 'message_pin_unique');
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('pinned_by')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('messaging_user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('allow_messages_from', 24)->default('everyone');
            $table->boolean('show_activity_status')->default(true);
            $table->boolean('send_read_receipts')->default(true);
            $table->boolean('allow_group_invites')->default(true);
            $table->json('muted_words')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'user_id'], 'messaging_settings_app_user_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('messaging_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('blocker_user_id');
            $table->unsignedBigInteger('blocked_user_id');
            $table->string('kind', 24)->default('block');
            $table->timestamps();
            $table->unique(['app_id', 'blocker_user_id', 'blocked_user_id'], 'messaging_block_unique');
            $table->foreign('blocker_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('blocked_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('reporter_user_id');
            $table->string('reason', 80);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->timestamps();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('reporter_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('messaging_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('started_by');
            $table->string('type', 16)->default('audio');
            $table->string('status', 24)->default('ringing')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('started_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_calls');
        Schema::dropIfExists('message_reports');
        Schema::dropIfExists('messaging_blocks');
        Schema::dropIfExists('messaging_user_settings');
        Schema::dropIfExists('message_pins');
        Schema::dropIfExists('message_receipts');
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('message_attachments');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_conversation_client_uuid_unique');
            $table->dropColumn(['client_uuid', 'scheduled_at', 'expires_at', 'pinned_at', 'delivered_at']);
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn(['role', 'nickname', 'pinned_at', 'notification_level', 'request_state', 'last_delivered_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'settings']);
        });
    }
};
